<?php

namespace App\Ai;

/** Models wrap JSON in prose or code fences; extract the first object and fail loudly if there is none. */
trait DecodesModelJson
{
    private function decodeJson(string $text): mixed
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        if (($start = strpos($text, '{')) === false || ($end = strrpos($text, '}')) === false) {
            throw new AiProviderException('The model did not return JSON.');
        }

        return json_decode(substr($text, $start, $end - $start + 1), true) ?? throw new AiProviderException('The model returned malformed JSON.');
    }
}
