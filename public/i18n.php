<?php
declare(strict_types=1);
// Interface translations. One dictionary (lang/messages.json) serves PHP rendering and public/assets/i18n.js.
// The language preference is a plain cookie, separate from the session cookie; French is the default.
const I18N_LANGUAGES = ['fr', 'en'];
const I18N_COOKIE = 'alternatech_lang';
function i18nMessages(): array {
    static $messages = null;
    return $messages ??= json_decode(file_get_contents(dirname(__DIR__).'/lang/messages.json'), true, 16, JSON_THROW_ON_ERROR);
}
function currentLang(): string {
    $lang = $_COOKIE[I18N_COOKIE] ?? '';
    return is_string($lang) && in_array($lang, I18N_LANGUAGES, true) ? $lang : 'fr';
}
// Same rules as I18N.t(): {name} placeholders, ".one"/".other" variants when a numeric count is given,
// nested ['k' => key] parameters, English then the key itself as fallbacks.
function tr(string $key, array $params = []): string {
    $all = i18nMessages(); $lang = currentLang();
    if (isset($params['count']) && is_int($params['count'])) {
        $plural = $key.($params['count'] === 1 ? '.one' : '.other');
        if (isset($all[$lang][$plural]) || isset($all['en'][$plural])) $key = $plural;
    }
    $message = $all[$lang][$key] ?? $all['en'][$key] ?? $key;
    return preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($params): string {
        if (!array_key_exists($m[1], $params)) return $m[0];
        $value = $params[$m[1]];
        return is_array($value) && isset($value['k']) ? tr($value['k'], $value['p'] ?? []) : (string)$value;
    }, $message);
}
function esc(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
// Attributes that let I18N.apply() re-translate the element after a language switch.
function i18nKey(string $key, array $params = []): string {
    return ' data-i18n="'.esc($key).'"'.($params ? ' data-i18n-args="'.esc(json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)).'"' : '');
}
function i18nText(string $key, array $params = [], string $tag = 'span', string $attributes = ''): string {
    return '<'.$tag.$attributes.i18nKey($key, $params).'>'.esc(tr($key, $params)).'</'.$tag.'>';
}
function i18nAttr(string $name, string $key, array $params = []): string {
    return ' '.$name.'="'.esc(tr($key, $params)).'" data-i18n-attr="'.esc(json_encode([$name => [$key, $params ?: null]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)).'"';
}
// Inline dictionary for public/assets/i18n.js; JSON_HEX_* keeps it inert inside <script>.
function i18nBootstrap(): string {
    $data = ['lang' => currentLang(), 'messages' => i18nMessages(), 'cookie' => I18N_COOKIE];
    return '<script>window.ALTERNATECH_I18N='.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>';
}
function langSwitch(): string {
    $html = '<div class="lang-switch" role="group"'.i18nAttr('aria-label', 'lang.label').'>';
    foreach (['fr' => 'Français', 'en' => 'English'] as $code => $name)
        $html .= '<button type="button" class="lang-option" data-lang-switch="'.$code.'" lang="'.$code.'" aria-label="'.$name.'" aria-pressed="'.(currentLang() === $code ? 'true' : 'false').'">'.strtoupper($code).'</button>';
    return $html.'</div>';
}
// Translates keyed static markup (index.html) on the server, so the first paint is already in the chosen language.
function translateHtml(string $html, ?callable $adjust = null): string {
    $document = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $document->documentElement->setAttribute('lang', currentLang());
    foreach ($document->querySelectorAll('[data-i18n]') as $node) {
        $args = $node->getAttribute('data-i18n-args');
        $node->textContent = tr($node->getAttribute('data-i18n'), $args ? json_decode($args, true, 8, JSON_THROW_ON_ERROR) : []);
    }
    foreach ($document->querySelectorAll('[data-i18n-attr]') as $node)
        foreach (json_decode($node->getAttribute('data-i18n-attr'), true, 8, JSON_THROW_ON_ERROR) as $name => [$key, $params])
            $node->setAttribute($name, tr($key, $params ?? []));
    if ($adjust) $adjust($document);
    return $document->saveHtml();
}
