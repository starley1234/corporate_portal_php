<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /* «Связаться с нами» — каркас эталона v6: шапка, сообщения .n2j-flash, форма .n2j-card */ ?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Связаться с нами']],
        'title'  => 'Связаться с нами',
        'lead'   => 'Мы всегда рады вашим вопросам, предложениям и отзывам. Заполните форму ниже — мы свяжемся с вами как можно скорее.',
    ]); ?>

    <div class="mt-6 max-w-3xl space-y-4">
        <?php if (isset($success_message)): ?>
        <div class="n2j-flash n2j-flash-ok" role="status">
            <span><i class="fas fa-circle-check mr-2"></i><?= HTML::chars($success_message) ?></span>
        </div>
        <?php endif; ?>

        <?php if (isset($errors) && !empty($errors)): ?>
        <div class="n2j-flash n2j-flash-err block" role="alert">
            <p class="font-bold">Ошибки при отправке формы:</p>
            <ul class="mt-2 list-inside list-disc">
                <?php foreach ($errors as $field => $error_messages): ?>
                    <?php if (is_array($error_messages)): ?>
                        <?php foreach ($error_messages as $message): ?>
                            <li><?= HTML::chars($message) ?></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><?= HTML::chars($error_messages) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!isset($success_message)): ?>
        <form method="post" action="<?= URL::site('contact/send') ?>" class="n2j-card space-y-5">

            <?php if (!in_array('login', $current_user_roles)):?>
            <div>
                <label for="email" class="n2j-label">Ваш Email <span class="text-rose-500">*</span></label>
                <input
                    type="email"
                    class="n2j-input mt-1.5"
                    id="email"
                    name="email"
                    value="<?= isset($values['email']) ? HTML::chars($values['email']) : '' ?>"
                    <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                    required
                    maxlength="255"
                >
                <?php if (isset($errors['email'])): ?>
                <p id="email-error" class="n2j-field-error mt-1.5">
                    <?= HTML::chars(is_array($errors['email']) ? reset($errors['email']) : $errors['email']) ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div>
                <label for="message" class="n2j-label">Ваше сообщение <span class="text-rose-500">*</span></label>
                <textarea
                    class="n2j-textarea mt-1.5"
                    id="message"
                    name="message"
                    rows="7"
                    <?= isset($errors['message']) ? 'aria-invalid="true" aria-describedby="message-error"' : '' ?>
                    required
                ><?= isset($values['message']) ? HTML::chars($values['message']) : '' ?></textarea>
                <?php if (isset($errors['message'])): ?>
                <p id="message-error" class="n2j-field-error mt-1.5">
                    <?= HTML::chars(is_array($errors['message']) ? reset($errors['message']) : $errors['message']) ?>
                </p>
                <?php endif; ?>
            </div>

            <?php if (isset($referer) && !empty($referer)): ?>
                <input type="hidden" name="referer" value="<?= HTML::chars($referer) ?>">
            <?php endif; ?>

            <p class="n2j-hint">Поля, отмеченные <span class="text-rose-500">*</span>, обязательны для заполнения.</p>

            <button type="submit" class="n2j-btn-cta">
                <i class="fas fa-paper-plane"></i><span>Отправить сообщение</span>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
