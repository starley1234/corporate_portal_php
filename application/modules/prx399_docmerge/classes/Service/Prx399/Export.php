<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Экспорт актуальной редакции prx399 в потребительские форматы.
 *
 *  Word (.docx) — конечный документ для человека: OOXML без внешних
 *  библиотек (ZipArchive из php-zip; расширения нет — fallback в Word-HTML
 *  .doc, который Word/WPS открывают как документ);
 *  Markdown (.md) — промежуточный формат для дальнейшей обработки (LLM,
 *  diff, вики): структура заголовками, текст без потерь;
 *  TXT — прежний плоский экспорт (Merge::render_text).
 *
 * Формат узлов — плоское дерево Merge::build: [{path, title, text, status}];
 * annulled-узлы не выводятся (как в render_text). Только детерминированные
 * строковые операции — текст из модели без инструкции в документ не попадает.
 */
class Service_Prx399_Export {

    /** Живые узлы (annulled пропускаются) — единый фильтр всех форматов. */
    protected static function _alive(array $nodes): array
    {
        return array_values(array_filter($nodes, static fn ($n) => (string) $n['status'] !== 'annulled'));
    }

    /** Словесный заголовок (раздел-4, ст-2, прил-1) или номерной пункт? */
    protected static function _is_word_heading($node): bool
    {
        return strpos((string) $node['path'], '-') !== FALSE;
    }

    // ------------------------------------------------------------ markdown

    /** Markdown для промежуточной обработки: заголовки отражают структуру. */
    public static function markdown($title, array $nodes): string
    {
        $lines = ['# '.trim((string) $title), '', '_Актуальная редакция, собранная по цепочке ДС (prx399)._', ''];

        foreach (self::_alive($nodes) as $node)
        {
            $title_line = trim((string) $node['title']);
            $text = trim((string) $node['text']);

            if ((string) $node['path'] === '0')
            {
                // преамбула: синтетический заголовок «Преамбула» в документ не пишем
                if ($text !== '') { $lines[] = $text; $lines[] = ''; }
                continue;
            }

            if (self::_is_word_heading($node))
            {
                $lines[] = '## '.$title_line;
            }
            elseif (substr_count((string) $node['path'], '.') === 0)
            {
                // пункт верхнего уровня — заголовок 3 уровня
                $lines[] = '### '.$title_line;
            }
            else
            {
                // подпункты — полужирная строка, чтобы вложенность не уезжала
                $lines[] = '**'.$title_line.'**';
            }
            if ($text !== '')
            {
                $lines[] = $text;
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines))."\n";
    }

    // ---------------------------------------------------------------- docx

    /** OOXML-тело document.xml — публично для контракт-теста. */
    public static function document_xml($title, array $nodes): string
    {
        $p = static fn (string $xml, string $style = NULL): string =>
            '<w:p>'.($style !== NULL ? '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>' : '').'<w:r>'.$xml.'</w:r></w:p>';
        $r = static fn (string $text, bool $bold = FALSE): string =>
            '<w:rPr>'.($bold ? '<w:b/>' : '').'</w:rPr><w:t xml:space="preserve">'.self::_esc($text).'</w:t>';

        $body = [$p($r(trim((string) $title), TRUE), 'Title'), $p($r('Актуальная редакция, собранная по цепочке ДС (prx399)'), 'Normal')];

        foreach (self::_alive($nodes) as $node)
        {
            $node_title = trim((string) $node['title']);
            $text = trim((string) $node['text']);

            if ((string) $node['path'] === '0')
            {
                if ($text !== '') { $body[] = $p($r($text)); }
                continue;
            }
            if (self::_is_word_heading($node))
            {
                $body[] = $p($r($node_title, TRUE), 'Heading1');
            }
            elseif (substr_count((string) $node['path'], '.') === 0)
            {
                $body[] = $p($r($node_title, TRUE), 'Heading2');
            }
            else
            {
                $body[] = $p($r($node_title, TRUE));
            }
            if ($text !== '')
            {
                $body[] = $p($r($text));
            }
        }

        // A4 (11906×16838 twips), поля 1134 (2 см)
        $body[] = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.implode('', $body).'</w:body></w:document>';
    }

    /** Word-обёртка документа: DOCX (php-zip) или Word-HTML .doc (fallback). */
    public static function docx($title, array $nodes, $base_name): array
    {
        if (class_exists('ZipArchive'))
        {
            $tmp = tempnam(sys_get_temp_dir(), 'prx399docx');
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE)
            {
                $zip->addFromString('[Content_Types].xml',
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    .'<Default Extension="xml" ContentType="application/xml"/>'
                    .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                    .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                    .'</Types>');
                $zip->addFromString('_rels/.rels',
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                    .'</Relationships>');
                $zip->addFromString('word/_rels/document.xml.rels',
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                    .'</Relationships>');
                $zip->addFromString('word/styles.xml', self::_styles_xml());
                $zip->addFromString('word/document.xml', self::document_xml($title, $nodes));
                $zip->close();

                $body = (string) file_get_contents($tmp);
                unlink($tmp);

                return ['name' => $base_name.'.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'body' => $body];
            }
            if (is_file($tmp)) { unlink($tmp); }
        }

        // php-zip недоступен: Word-HTML (.doc) — Word и WPS открывают как документ
        return ['name' => $base_name.'.doc', 'mime' => 'application/msword', 'body' => self::wordhtml($title, $nodes)];
    }

    protected static function _esc($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Минимальные стили: Title/Heading1/Heading2 — для навигации в Word. */
    protected static function _styles_xml(): string
    {
        $style = static fn (string $id, string $name, string $rpr, string $ppr = ''): string =>
            '<w:style w:type="paragraph" w:styleId="'.$id.'"><w:name w:val="'.$name.'"/><w:basedOn w:val="Normal"/>'
            .($ppr !== '' ? '<w:pPr>'.$ppr.'</w:pPr>' : '').'<w:rPr>'.$rpr.'</w:rPr></w:style>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="120"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            .$style('Title', 'Title', '<w:b/><w:sz w:val="32"/>', '<w:jc w:val="center"/><w:spacing w:after="240"/>')
            .$style('Heading1', 'heading 1', '<w:b/><w:sz w:val="28"/>', '<w:spacing w:before="240"/>')
            .$style('Heading2', 'heading 2', '<w:b/><w:sz w:val="24"/>', '<w:spacing w:before="180"/>')
            .'</w:styles>';
    }

    /** Fallback: Word-HTML (.doc). */
    public static function wordhtml($title, array $nodes): string
    {
        $h = static fn (int $level, string $text): string => '<h'.$level.'>'.htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8').'</h'.$level.'>';

        $out = ['<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8').'</title></head><body>'];
        $out[] = '<h1>'.htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8').'</h1>';
        foreach (self::_alive($nodes) as $node)
        {
            $node_title = trim((string) $node['title']);
            $text = trim((string) $node['text']);
            if ((string) $node['path'] === '0')
            {
                if ($text !== '') { $out[] = '<p>'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</p>'; }
                continue;
            }
            $out[] = self::_is_word_heading($node) ? $h(2, $node_title) : $h(3, $node_title);
            if ($text !== '') { $out[] = '<p>'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</p>'; }
        }
        $out[] = '</body></html>';

        return implode('', $out);
    }
}
