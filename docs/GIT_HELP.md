У вас для каждого сайта сделан **отдельный Deploy Key** и настроен файл конфигурации `config`. Это самый правильный подход, потому что GitHub **не разрешает** использовать один и тот же ключ в качестве *Deploy Key* в разных репозиториях.

Давайте сделаем всё по аналогии для `n2j.ru`. Потребуется буквально 2 минуты.

---

### Шаг 1. Генерируем ключ именно для `n2j.ru`

Выполните команду в консоли:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/deploy_key_n2j_ru -N ""
```
*(Эта команда создаст пару ключей без пароля)*

Посмотрим публичный ключ:
```bash
cat ~/.ssh/deploy_key_n2j_ru.pub
```
**Скопируйте** выведенную на экран строку (начинается с `ssh-ed25519 ...`).

---

### Шаг 2. Добавляем ключ в репозиторий на GitHub

1. Откройте нужный репозиторий на GitHub.
2. Перейдите в **Settings** $\to$ слева **Deploy keys** $\to$ нажмите **Add deploy key**.
3. Заполните:
   * **Title**: `n2j.ru`
   * **Key**: вставьте скопированную строку.
   * **ОБЯЗАТЕЛЬНО:** Поставьте галочку **«Allow write access»** (чтобы работал ваш `git push`).
4. Нажмите **Add key**.

---

### Шаг 3. Прописываем алиас в `~/.ssh/config`

Чтобы Git знал, какой именно ключ использовать для этого сайта, пропишем алиас:

```bash
cat << 'EOF' >> ~/.ssh/config

Host github-n2j
    HostName github.com
    User git
    IdentityFile ~/.ssh/deploy_key_n2j_ru
    IdentitiesOnly yes
EOF
```

Проверим связь (должно поприветствовать вас или ваш ключ):
```bash
ssh -T github-n2j
```
*(Если спросит `Are you sure you want to continue connecting (yes/no)?` — напишите `yes` и нажмите Enter).*

---

### Шаг 4. Клонируем проект в `/home/c/cs11853/n2j.ru`

**Внимание на адрес:** вместо обычного `git@github.com:...` мы используем наш хост-алиас `github-n2j:...`.

#### Если папка `/home/c/cs11853/n2j.ru` пустая:
```bash
git clone -c pack.threads=1 github-n2j:starley1234/n2j.ru.git /home/c/cs11853/n2j.ru
```

#### Если в `/home/c/cs11853/n2j.ru` уже есть файлы:
```bash
cd /home/c/cs11853/n2j.ru
git init
git remote add origin github-n2j:ВАШ_АККАУНТ/ВАШ_РЕПОЗИТОРИЙ.git
git fetch origin
git checkout -f -B main origin/main
```
*(Если основная ветка называется `master`, замените `main` на `master`)*

---

### Шаг 5. Финальный штрих для PHP
Чтобы PHP-скрипт не ругался на права директории, выполните:
```bash
git config --global --add safe.directory /home/c/cs11853/n2j.ru
```

Готово! Теперь и клонирование выполнено, и ваш контроллер `Controller_Git` сможет автоматически пушить и пуллить изменения через этот ключ.