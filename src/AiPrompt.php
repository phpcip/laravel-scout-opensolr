<?php

namespace Opensolr\ScoutOpensolr;

/**
 * The canonical Opensolr RAG prompt: the document context and the instruction wrapped around it.
 *
 * WHY THIS IS ITS OWN CLASS, static and dependency-free (no Guzzle, no Scout, no Laravel):
 * the prompt is a cross-repo contract, not a detail of this package. The identical builder runs
 * on the hosted search page, in the /rag-in-60-seconds sandbox, in the Drupal module, in the
 * WordPress plugin and in the four Python packages. An answer has to read the same whichever door
 * the user came through, and the only way to prove that is mechanically: feed every port the same
 * fixture and diff the bytes. That proof needs a file a bare `php harness.php` can require on its
 * own — the moment prompt building sits inside a class that opens HTTP connections, the parity
 * check needs the framework booted and stops being run.
 *
 * The proof, for this package:
 *
 *     fixture.json -> AiPrompt::instruction(AiPrompt::context(...), $query)
 *     md5 = 7e70ac630973aa90936de643eacbb365   (10693 bytes)
 *
 * Source of truth: ai_summary_stream() and tika_search() in
 * addons/default/modules/solr_manager/controllers/solr_manager.php on the platform, plus
 * ai_mark_fragment() and ai_excerpt() in addons/default/helpers/generic_helper.php.
 *
 * CHANGE ANY BYTE HERE AND YOU CHANGE IT IN ALL TEN PROMPT BUILDERS. The wording was measured
 * twice on 2026-08-29 against the generation model directly — three test sets, seven candidate
 * phrasings, three runs each — and the rationale for every load-bearing piece (documents first,
 * question last, the pinned refusal opening, the ===== fences, temperature 0.1) lives at the
 * platform call site. Do not "improve" it without re-running that measurement.
 */
final class AiPrompt
{
    /**
     * The instruction that wraps the documents, with the two values the call site fills in.
     *
     * Named and public because the other packages expose theirs at module scope: a constant can
     * be asserted against, an inline string buried in a method body cannot, and this one drifted
     * out of step precisely because nothing could see it. {document_count} appears three times on
     * purpose — the model is told the number up front, again when asked to use all of them, and
     * once more in the refusal clause.
     */
    public const DEFAULT_RAG_INSTRUCTION = "Those were the {document_count} documents.\n\n"
        . 'Now answer the question below using only facts stated in those documents. '
        . 'Find ALL of the {document_count} documents that are relevant to the question, even '
        . 'when the question describes the subject in completely different words than a '
        . 'document does, and answer the question based on those. Where more than one of '
        . "them bears on the question, combine what each one adds into a single answer.\n"
        . 'Write the answer itself, formatted in Markdown for reading. Begin with one '
        . 'sentence that answers the question directly. Whenever the answer covers more '
        . 'than one development, position or fact — which is most of the time — set '
        . 'the detail out as a Markdown list, each item on its own line opening with a '
        . 'bold lead-in that names it. Keep it as prose only if there is genuinely just '
        . 'one thing to say. Be thorough: cover every distinct point the documents offer '
        . 'that bears on the question, with the concrete details — the people, places, '
        . 'numbers, dates and named events involved. Do not stop at the first thing you can '
        . 'say. Never invent generic headings such as "Overview", "Key Points" or '
        . '"Summary". Never begin with "Based on" or "According to", and never end with '
        . 'a sentence about the documents or the context. Do not name documents, do not say '
        . "which ones you used, and do not comment on the ones you did not use.\n"
        . 'Only if not one of the {document_count} documents is about the question, reply with '
        . 'a single sentence that starts "There is no information about" and then names '
        . "what they cover instead.\n\n"
        . "Question: {question}\n"
        . 'Answer:';

    /**
     * Build the document context handed to the model.
     *
     * @param array $docs Retrieval hits, best first, as Solr returns them.
     * @param array $hl   Highlight fragments keyed by document id, each a map of field => list.
     * @param int $topN Number of leading hits considered. Four, matching the platform.
     * @param int $maxWords Word cap per document body.
     * @return string Ends with a blank line, so the instruction can be concatenated straight on.
     */
    public static function context(array $docs, array $hl, int $topN = 4, int $maxWords = 1500): string
    {
        // The builder walks positions 0..topN-1, so the list has to be densely keyed. Solr's own
        // response already is; a caller that filtered hits beforehand may not be.
        $docs = array_values($docs);

        // Relevance floor (platform, 2026-08-25). The top N hits are taken regardless of score,
        // so a narrow question arrives with one good match and three unrelated articles. The
        // model then sees that most of its context does not answer the question and hedges:
        // "the content does not mention X, however...". Keep only what scores at least half of
        // the best. The best is measured across those SAME first N rows and no further: a
        // stronger 5th hit that will never be shown must not raise the bar for the four that are.
        $topScore = 0.0;
        for ($i = 0; $i < $topN; $i++) {
            if (isset($docs[$i]['score'])) {
                $topScore = max($topScore, (float) $docs[$i]['score']);
            }
        }

        $context = '';
        for ($i = 0; $i < $topN; $i++) {
            if (!isset($docs[$i])) {
                continue;
            }
            // Documents without a score are kept: only drop what we can prove is weak.
            if ($topScore > 0.0 && isset($docs[$i]['score'])
                && (float) $docs[$i]['score'] < $topScore * 0.5) {
                continue;
            }

            // Explicit document boundaries (platform, 2026-08-29). Without them the model reads
            // four articles as one wall of text and either denies everything or attaches the
            // question to whichever story it hit first. The number counts documents KEPT, not
            // input rows, which is why it is derived from the context built so far rather than
            // from $i — a hit dropped by the floor must not leave a gap in the numbering.
            $n = count(explode('===== DOCUMENT ', $context));
            $context .= '===== DOCUMENT ' . $n . " =====\n";
            // Title and description are emitted even when empty: the model is reading a fixed
            // three-line header per document, and a missing line would shift the body up into
            // the slot where it expects the description.
            $context .= self::flatten($docs[$i]['title'] ?? '') . "\n";
            $context .= self::flatten($docs[$i]['description'] ?? '') . "\n";

            // Solr's own highlight fragments go before the long excerpt, so the model reads the
            // text that actually matched while it still has full attention on this document.
            // Measured in the RAG sandbox: they are the difference between a one-line answer and
            // a real one. <em> markers belong to a search UI, not to a prompt, so every tag goes.
            $docId = $docs[$i]['id'] ?? '';
            if (is_array($docId)) {
                $docId = reset($docId);
            }
            $docHl = (array) ($hl[$docId] ?? []);
            $snippets = [];
            foreach (['title', 'description', 'text'] as $field) {
                foreach ((array) ($docHl[$field] ?? []) as $snippet) {
                    $snippet = self::markFragment(strip_tags((string) $snippet));
                    if ($snippet !== '') {
                        $snippets[] = $snippet;
                    }
                }
            }
            if ($snippets !== []) {
                $context .= "MOST RELEVANT EXCERPTS:\n";
                foreach ($snippets as $snippet) {
                    $context .= '- ' . $snippet . "\n";
                }
                // Blank line closes the fragment list, so the full text below reads as its own
                // paragraph and not as a continuation of the last fragment.
                $context .= "\n";
            }

            // text_t is CONCATENATED with text, never substituted for it (platform, 2026-08-29).
            // On some sites the JSON-LD field carries real article content; on others it holds
            // only scaffolding ("Is Accessible For Free: False"), and preferring it there leaves
            // the model with metadata and no article. Keeping both never loses content; the
            // 50-byte floor drops the empty husks.
            $textT = trim(self::flatten($docs[$i]['text_t'] ?? ''));
            $body = strlen($textT) > 50
                ? $textT . "\n" . self::flatten($docs[$i]['text'] ?? '')
                : self::flatten($docs[$i]['text'] ?? '');

            $context .= self::excerpt($body, $maxWords) . "\n";
            $context .= '===== END OF DOCUMENT ' . $n . " =====\n\n";
        }

        return $context;
    }

    /**
     * Wrap the context in the instruction — the whole prompt, as one string.
     *
     * The documents come FIRST and the question LAST because that ordering was measured: removing
     * the trailing "Question:" slot from the runner-up phrasing collapsed the adversarial score
     * from 7/7 to 3/7. Whatever sits in the last slot before generation is what the model reaches
     * for, so the escape hatch stays in the middle and the question stays at the end.
     *
     * @param string $context Output of context(), already ending in a blank line.
     * @param string $query The user's question, verbatim.
     */
    public static function instruction(string $context, string $query): string
    {
        // Count the fences rather than tracking a counter: the context is the only thing that
        // knows how many documents survived the relevance floor. max(1,...) keeps the sentence
        // grammatical when retrieval came back empty.
        $count = max(1, substr_count($context, '===== DOCUMENT '));

        return $context . "\n\n" . strtr(self::DEFAULT_RAG_INSTRUCTION, [
            '{document_count}' => (string) $count,
            '{question}' => $query,
        ]);
    }

    /**
     * Mark a highlighter fragment as the cut-out excerpt it is.
     *
     * Solr slices fragments mid-sentence: they begin and end wherever the window fell. Unmarked,
     * they read to a model as complete sentences, and a small model stitches a fragment onto the
     * paragraph that follows and treats the join as one statement. An ellipsis on the open end
     * says plainly that text is missing — the same convention the search UI shows the reader.
     */
    private static function markFragment(string $fragment): string
    {
        $fragment = trim(preg_replace('/\s+/u', ' ', $fragment));
        if ($fragment === '') {
            return '';
        }
        // Starts mid-sentence when the first character is not an opening capital, digit or quote.
        // \p{Lu} and \p{N} rather than A-Z0-9: the corpora are multilingual, and a Greek or
        // Cyrillic capital opening a sentence must not be mistaken for a mid-sentence cut.
        if (!preg_match('/^["\'(\[]?[\p{Lu}\p{N}]/u', $fragment)) {
            $fragment = '... ' . $fragment;
        }
        // Ends mid-sentence when there is no closing punctuation.
        if (!preg_match('/[.!?\x{2026}]["\')\]]?$/u', $fragment)) {
            $fragment .= ' ...';
        }

        return $fragment;
    }

    /**
     * Cut text to $maxWords words.
     *
     * It CUTS the original string and never rebuilds it from the matched words. Splitting on
     * whitespace and imploding with single spaces threw away every newline and every run of
     * indentation, so a page reached the model as one unbroken line with its paragraphs, lists
     * and code blocks flattened — which is where the hallucinations and the empty answers came
     * from (2026-08-26). Text handed to a model has to arrive as written; the structure is part
     * of the meaning.
     */
    private static function excerpt(string $text, int $maxWords): string
    {
        if ($text === '' || $maxWords <= 0) {
            return '';
        }
        // PREG_OFFSET_CAPTURE gives the byte offset of every word, so the cut lands on the
        // original string at the end of the Nth word.
        if (!preg_match_all('/\S+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        if (count($m[0]) <= $maxWords) {
            return $text;
        }
        $last = $m[0][$maxWords - 1];

        return substr($text, 0, $last[1] + strlen($last[0]));
    }

    /**
     * Flatten one Solr field to a string.
     *
     * Solr returns multiValued fields as JSON lists, so title/description/text can arrive as
     * arrays depending on the index schema. Casting one of those to string yields "Array" and a
     * warning, and the model gets that instead of the document.
     */
    private static function flatten(mixed $value): string
    {
        return is_array($value)
            ? implode(' ', array_map('strval', $value))
            : (string) ($value ?? '');
    }
}
