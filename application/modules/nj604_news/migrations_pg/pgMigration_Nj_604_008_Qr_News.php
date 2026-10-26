<?php defined('SYSPATH') or die('No direct script access.');

/** Новость о nj510_qr. Повторный deploy не создаёт дубль. */
class pgMigration_Nj_604_008_Qr_News extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
INSERT INTO "nj_604_news" (kind, title, body_md, href, tag, published, published_at)
SELECT 'manual', 'QR-коды с логотипом: один или целый пакет',
       'Новый бесплатный генератор QR-кодов: ссылка, текст, Wi-Fi, визитка, письмо, телефон или SMS. Выберите готовый стиль, свои цвета, форму элементов и загрузите логотип по центру. Картинки скачиваются в PNG, SVG и JPEG. Для массовой генерации вставьте список или загрузите CSV, TSV либо TXT — до 200 кодов одним ZIP-архивом, с прогрессом, отменой и списком файлов. Читаемость проверяется в браузере. Контакты, пароли и картинки не отправляются на сервер; регистрация не нужна.',
       '/qr', 'сервисы', TRUE, now()
WHERE NOT EXISTS (
    SELECT 1 FROM "nj_604_news"
    WHERE title = 'QR-коды с логотипом: один или целый пакет' AND href = '/qr'
);
SQL);
    }

    public function down()
    {
        $this->run_query("DELETE FROM \"nj_604_news\" WHERE title = 'QR-коды с логотипом: один или целый пакет' AND href = '/qr';");
    }
}
