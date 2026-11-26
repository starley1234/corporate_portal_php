<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Экспорт актуальной редакции prx399 в потребительские форматы.
 *
 * Оформление — по ГОСТ Р 7.0.97-2016 (деловые документы) и конвенции деловых
 * договоров: Times New Roman 14 pt; поля — левое 30 мм (договоры подшиваются),
 * правое 10 мм, верхнее/нижнее 20 мм; абзацный отступ 1,25 см; текст по
 * ширине; заголовки разделов по центру, без точки в конце; пункт — «1.1.
 * Текст…» ЕДИНЫМ абзацем с номером в строке (как в исходном договоре), а не
 * синтетические заголовки «Пункт N.».
 *
 * Титул документа берётся ИЗ ТЕКСТА (первая титульная строка — «ДОГОВОР
 * ПОСТАВКИ №…»), а не из названия пакета (там имя файла). Служебных строк в
 * документе нет. Артефакты разбора скана (титул+стороны, попавшие в «пункт»)
 * рендерятся как преамбула: без номера пункта, обычными абзацами.
 *
 * Word (.docx) — OOXML без внешних библиотек (php-zip; расширения нет —
 * fallback в Word-HTML .doc); Markdown (.md) — для промежуточной обработки;
 * TXT — прежний плоский экспорт (Merge::render_text). Только детерминированные
 * строковые операции: текст из модели без инструкции в документ не попадает.
 */
class Service_Prx399_Export {

    /** Титульная строка называет вид документа. */
    const TITLE_RE = '/(договор|соглашение|контракт)/ui';

    /** Узел-«преамбула»: титул и стороны, попавшие в «пункт» при разборе скана. */
    const PREAMBLE_RE = '/(именуем\w+ в дальнейшем|заключили настоящий|о нижеследующем)/ui';

    // ------------------------------------------------------------ extraction

    /** Живые узлы без точных дублей (скан повторяет узлы; дубль одного текста — артефакт). */
    protected static function _alive(array $nodes): array
    {
        $seen = [];
        $out = [];
        foreach ($nodes as $node)
        {
            if ((string) $node['status'] === 'annulled') continue;
            $key = md5(json_encode([$node['path'], $node['title'], $node['text']], JSON_UNESCAPED_UNICODE));
            if (isset($seen[$key])) continue;
            $seen[$key] = TRUE;
            $out[] = $node;
        }

        return $out;
    }

    /** Доля заглавных букв в строке (0..1). */
    protected static function _caps_ratio(string $line): float
    {
        $letters = (string) preg_replace('~[^\pL]~u', '', $line);
        if ($letters === '')
        {
            return 0.0;
        }
        $upper = (string) preg_replace('~[^\pLu]~u', '', $letters);

        return mb_strlen($upper) / mb_strlen($letters);
    }

    /** Титульная строка: вид договора, заметная, почти целиком заглавными. */
    protected static function _is_title_line(string $line): bool
    {
        $line = trim($line);

        return mb_strlen($line) >= 8 AND mb_strlen($line) <= 160
            AND self::_caps_ratio($line) >= 0.5
            AND preg_match(self::TITLE_RE, $line) === 1;
    }

    /**
     * Заголовок документа — из самого текста: первая титульная строка
     * изымается из узла и становится титом. ['', $nodes] — титула в тексте
     * нет: заголовок не рисуем вовсе (название пакета/имя файла — не титул).
     */
    public static function title_of(array $nodes): array
    {
        foreach ($nodes as $i => $node)
        {
            if ((string) $node['status'] === 'annulled') continue;
            $text = (string) $node['text'];
            if (trim($text) === '') continue;

            $lines = preg_split('~\r?\n~', $text) ?: [];
            foreach ($lines as $k => $line)
            {
                if (self::_is_title_line((string) $line))
                {
                    unset($lines[$k]);
                    $nodes[$i]['text'] = trim(implode("\n", $lines));

                    return [trim((string) $line), $nodes];
                }
            }
            break; // первый содержательный узел без титула — дальше не ищем
        }

        return ['', $nodes];
    }

    /** Имя файла: из титула, безопасные символы; иначе нейтральное. */
    public static function file_base($title, $contract_id): string
    {
        $base = trim((string) preg_replace('~[^\pL\d ().,№_-]+~u', '', (string) $title));
        $base = trim((string) preg_replace('~\s+~u', ' ', $base));
        if ($base === '')
        {
            $base = 'contract-'.(int) $contract_id.'-consolidated';
        }

        return mb_substr($base, 0, 80);
    }

    // ------------------------------------------------------------- semantics

    /** Словесный заголовок («раздел-4», «ст-2», «прил-1»). */
    protected static function _is_word_heading($node): bool
    {
        return strpos((string) $node['path'], '-') !== FALSE;
    }

    /** Заголовок раздела: верхний уровень «1. ПРЕДМЕТ ДОГОВОРА» — капс, без точки. */
    protected static function _is_section($node): bool
    {
        $path = (string) $node['path'];
        if ($path === '0' OR strpos($path, '-') !== FALSE OR strpos($path, '.') !== FALSE)
        {
            return FALSE;
        }
        $text = trim((string) $node['text']);
        if ($text === '' OR mb_strlen($text) > 120)
        {
            return FALSE;
        }
        if (preg_match('~[.!?]\z~u', $text) === 1)
        {
            return FALSE; // заканчивается точкой — это пункт, не заголовок
        }

        return self::_caps_ratio($text) >= 0.6;
    }

    /** Артефакт разбора: титул и стороны в «пункте» — рендерим как преамбулу. */
    protected static function _is_preamble_like(string $text): bool
    {
        return preg_match(self::PREAMBLE_RE, $text) === 1;
    }

    /** Текст узла уже начинается со своего номера (модель вернула «2.1. …»)? */
    protected static function _numbered($path, string $text): bool
    {
        return preg_match('~^\s*(\d{1,3}(?:\.\d{1,3}){0,4})(?:[.)])?\s~u', $text, $m) === 1
            AND strtolower($m[1]) === strtolower((string) $path);
    }

    /**
     * Блоки документа: [тип, текст]. Типы: title (титул, по центру),
     * heading (заголовок раздела, по центру), para (по ширине, отступ
     * 1,25 см), plain (по ширине, без отступа — город/дата, реквизиты).
     */
    protected static function _blocks($title, array $nodes): array
    {
        $blocks = [];
        if ((string) $title !== '')
        {
            $blocks[] = ['title', (string) $title];
        }

        foreach (self::_alive($nodes) as $node)
        {
            $path = (string) $node['path'];
            $node_title = trim((string) $node['title']);
            $text = trim((string) $node['text']);

            if ($path === '0')
            {
                if ($text !== '')
                {
                    foreach (self::_paragraph_lines($text) as $block) { $blocks[] = $block; }
                }
                continue;
            }

            if (self::_is_word_heading($node) OR self::_is_section($node))
            {
                $heading = self::_is_section($node) ? $path.'. '.$text : $node_title;
                $blocks[] = ['heading', rtrim($heading, '. ')]; // ГОСТ: без точки в конце
                if ($text !== '' AND self::_is_word_heading($node))
                {
                    $blocks[] = ['para', $text]; // текст под словесным заголовком
                }
                continue;
            }

            if ($text === '' OR self::_is_preamble_like($text))
            {
                // «пункт», в котором титул+стороны: обычные абзацы, без номера
                foreach (self::_paragraph_lines($text) as $block) { $blocks[] = $block; }
                continue;
            }

            // ПУНКТ: «1.1. Текст…» — номер в строке абзаца, как в договоре
            $blocks[] = ['para', self::_numbered($path, $text) ? $text : $path.'. '.$text];
        }

        return $blocks;
    }

    /** Текст блока → абзацы: длинные с отступом и по ширине, короткие без. */
    protected static function _paragraph_lines(string $text): array
    {
        $out = [];
        foreach (preg_split('~\r?\n~', $text) ?: [] as $line)
        {
            $line = trim((string) $line);
            if ($line === '') continue;
            $out[] = [mb_strlen($line) > 80 ? 'para' : 'plain', $line];
        }

        return $out ?: [['plain', $text]];
    }

    // ------------------------------------------------------------- markdown

    /** Markdown для промежуточной обработки: структура заголовками, текст без потерь. */
    public static function markdown($title, array $nodes): string
    {
        $lines = [];
        foreach (self::_blocks($title, $nodes) as [$type, $text])
        {
            if ($type === 'title')
            {
                $lines[] = '# '.$text;
            }
            elseif ($type === 'heading')
            {
                $lines[] = '## '.$text;
            }
            else
            {
                $lines[] = $text;
            }
            $lines[] = '';
        }

        $out = trim(implode("\n", $lines));

        return $out === '' ? '' : $out."\n";
    }

    // ----------------------------------------------------------------- docx

    /** OOXML-тело document.xml — публично для контракт-теста. */
    public static function document_xml($title, array $nodes): string
    {
        $body = [];
        foreach (self::_blocks($title, $nodes) as [$type, $text])
        {
            $body[] = self::_para((string) $text, $type);
        }

        // ГОСТ Р 7.0.97-2016: поля — левое 30 мм (подшивка), правое 10 мм,
        // верхнее/нижнее 20 мм; нумерация страниц — со второй (titlePg).
        $body[] = '<w:sectPr><w:footerReference w:type="default" r:id="rId2"/><w:titlePg/>'
            .'<w:pgSz w:w="11906" w:h="16838"/>'
            .'<w:pgMar w:top="1134" w:right="567" w:bottom="1134" w:left="1701"/></w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body>'.implode('', $body).'</w:body></w:document>';
    }

    /** Параграф OOXML по типу блока: TNR 14pt, по ширине, отступ 1,25 см. */
    protected static function _para(string $text, string $type): string
    {
        $bold = ($type === 'title' OR $type === 'heading') ? '<w:b/>' : '';
        $run = '<w:r><w:rPr>'.$bold.'</w:rPr><w:t xml:space="preserve">'.self::_esc($text).'</w:t></w:r>';

        $ppr = '<w:spacing w:after="120" w:line="276" w:lineRule="auto"/>';
        if ($type === 'title')
        {
            $ppr = '<w:pStyle w:val="Title"/>';
        }
        elseif ($type === 'heading')
        {
            $ppr = '<w:pStyle w:val="Heading1"/>';
        }
        elseif ($type === 'para')
        {
            $ppr .= '<w:ind w:firstLine="709"/><w:jc w:val="both"/>';
        }

        return '<w:p><w:pPr>'.$ppr.'</w:pPr>'.$run.'</w:p>';
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
                    .'<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
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
                    .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
                    .'</Relationships>');
                $zip->addFromString('word/styles.xml', self::_styles_xml());
                $zip->addFromString('word/footer1.xml', self::_footer_xml());
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

    /** Стили: TNR 14pt (ГОСТ), Title/Heading1 по центру, заголовки — keepNext. */
    protected static function _styles_xml(): string
    {
        $style = static fn (string $id, string $name, string $rpr, string $ppr = ''): string =>
            '<w:style w:type="paragraph" w:styleId="'.$id.'"><w:name w:val="'.$name.'"/><w:basedOn w:val="Normal"/>'
            .($ppr !== '' ? '<w:pPr>'.$ppr.'</w:pPr>' : '').'<w:rPr>'.$rpr.'</w:rPr></w:style>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="28"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            .$style('Title', 'Title', '<w:b/><w:sz w:val="32"/>', '<w:jc w:val="center"/><w:spacing w:before="0" w:after="360"/>')
            .$style('Heading1', 'heading 1', '<w:b/><w:sz w:val="28"/>', '<w:keepNext/><w:jc w:val="center"/><w:spacing w:before="240" w:after="160"/>')
            .'</w:styles>';
    }

    /** Нумерация страниц по ГОСТ: по центру верхнего поля, со второй страницы. */
    protected static function _footer_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>2</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            .'</w:p></w:ftr>';
    }

    /** Fallback: Word-HTML (.doc). */
    public static function wordhtml($title, array $nodes): string
    {
        $esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $out = ['<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$esc((string) $title).'</title>'
            .'<style>body{font:14pt "Times New Roman",serif;} h1{font-size:16pt;text-align:center;}'
            .' h2{font-size:14pt;text-align:center;} p{margin:0 0 6pt;text-align:justify;text-indent:1.25cm;}'
            .' p.ni{text-indent:0;}</style></head><body>'];
        foreach (self::_blocks($title, $nodes) as [$type, $text])
        {
            if ($type === 'title') { $out[] = '<h1>'.$esc((string) $text).'</h1>'; }
            elseif ($type === 'heading') { $out[] = '<h2>'.$esc((string) $text).'</h2>'; }
            elseif ($type === 'para') { $out[] = '<p>'.$esc((string) $text).'</p>'; }
            else { $out[] = '<p class="ni">'.$esc((string) $text).'</p>'; }
        }
        $out[] = '</body></html>';

        return implode('', $out);
    }
}
