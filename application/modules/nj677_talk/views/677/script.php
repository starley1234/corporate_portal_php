<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Скрипт разговора (/talk, nj677).
 *
 * Правила взяты у голосовых помощников (Алиса и другие), потому что они решают
 * ровно те проблемы, на которые жалуются люди в разговоре с ИИ:
 *
 *   1. МИКРОФОН ВЗЯТ ТОЛЬКО НА ВРЕМЯ СЛУШАНИЯ. Браузер (и система) переводит
 *      звук в «режим связи», пока открыт микрофон: на Bluetooth-гарнитуре это
 *      HFP вместо A2DP — весь звук страницы становится глухим и тихим, а на
 *      Windows включается «коммуникационное» подавление. Поэтому поток
 *      закрывается сразу после реплики и берётся снова, когда пора слушать.
 *   2. НЕ ПИСАТЬ, ПОКА ГОВОРИМ. Даже пока микрофон открыт (режим перебивания),
 *      запись не идёт: иначе в распознавание уходит эхо колонок, и на тишине
 *      распознаватель выдаёт титры обучающих видео («Редактор субтитров…»).
 *   3. СИГНАЛ ПЕРЕД СЛУШАНИЕМ. Звонкий короткий «дин», как звук активации у
 *      Алисы: он говорит «слушаю», не отвлекая от разговора.
 *   4. ПАУЗА ПОСЛЕ ОТВЕТА. 0,5 с на затухание колонок, сигнал, потом слушаем.
 *   5. НЕ ОТПРАВЛЯТЬ ТИШИНУ и не принимать собственный ответ за реплику.
 *
 * Состояния: idle → listening → (запись) → thinking → speaking → listening.
 * Запись возможна только в listening.
 */
?>
<script>
(function () {
    'use strict';

    var root = document.getElementById('talk');
    var cfgEl = document.getElementById('talk-config');

    if (!root || !cfgEl) return;

    var cfg;

    try { cfg = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }

    var log        = document.getElementById('talk-log');
    var empty      = document.getElementById('talk-empty');
    var statusText = document.getElementById('talk-status-text');
    var spin       = document.getElementById('talk-spin');
    var elapsedEl  = document.getElementById('talk-elapsed');
    var startBtn   = document.getElementById('talk-start');
    var stopBtn    = document.getElementById('talk-stop');
    var newBtn     = document.getElementById('talk-new');
    var textForm   = document.getElementById('talk-text-form');
    var textInput  = document.getElementById('talk-text');
    var voiceSel   = document.getElementById('talk-voice');
    var bargeBox   = document.getElementById('talk-barge');
    var cueBox     = document.getElementById('talk-cue');
    var tplUser    = document.getElementById('talk-tpl-user');
    var tplBot     = document.getElementById('talk-tpl-bot');

    if (!log || !startBtn || !stopBtn) return;

    var TICK_MS = 60;

    var S = {
        state:      'idle',        // idle | listening | thinking | speaking
        talk:       cfg.talk || '',
        voice:      cfg.voice || '',
        listenFrom: 0,             // до этого момента микрофон не слушаем (сигнал)
        cooldown:   0              // возврат после ответа: затухание колонок
    };

    var ctx = null, mic = null, source = null, analyser = null, frame = null;
    var tickTimer = null, acquiring = null, micError = null;
    var recorder = null, chunks = [], segStart = 0, voicedMs = 0, lastVoice = 0;
    var noiseFloor = 0.008;
    var bargeTicks = 0, echoBase = 0, echoReady = false;
    var audio = null, audioUrl = '', lastAnswer = '';
    var queue = [], pending = {};
    var clockTimer = null, clockStart = 0;

    // ---------------------------------------------------------- интерфейс

    function setStatus(text, busy) {
        if (statusText) statusText.textContent = text;
        if (spin) spin.classList.toggle('hidden', !busy);
    }

    function notify(text) {
        if (window.N2J && typeof window.N2J.toast === 'function') {
            window.N2J.toast(text, 0, 'err');
        }
    }

    function seconds(ms) {
        return (Math.max(0, ms) / 1000).toFixed(1).replace('.', ',') + ' с';
    }

    function startClock() {
        if (!elapsedEl) return;
        clockStart = Date.now();
        elapsedEl.classList.remove('hidden');
        elapsedEl.textContent = '0,0 с';
        clearInterval(clockTimer);
        clockTimer = setInterval(function () {
            elapsedEl.textContent = ((Date.now() - clockStart) / 1000).toFixed(1).replace('.', ',') + ' с';
        }, 150);
    }

    function stopClock() {
        clearInterval(clockTimer);
        clockTimer = null;
        if (elapsedEl) elapsedEl.classList.add('hidden');
    }

    function addBubble(kind, text, meta) {
        var tpl = kind === 'user' ? tplUser : tplBot;

        if (!tpl || !tpl.content || !tpl.content.firstElementChild) return;

        var node = tpl.content.firstElementChild.cloneNode(true);
        var body = node.querySelector('[data-part="text"]');
        var tail = node.querySelector('[data-part="meta"]');

        if (body) body.textContent = text;

        if (tail) {
            if (meta) { tail.textContent = meta; } else { tail.remove(); }
        }

        if (empty && empty.parentNode) empty.parentNode.removeChild(empty);
        log.appendChild(node);
        log.scrollTop = log.scrollHeight;
    }

    function now() { return Date.now(); }

    /** Разговор ещё идёт? Ответы, пришедшие после «Закончить», не применяем. */
    function alive() { return S.state !== 'idle'; }

    function bargeOn() { return !!(bargeBox && bargeBox.checked); }
    function cueOn() { return !(cueBox && !cueBox.checked); }

    // ---------------------------------------------------- сигналы (earcons)

    /** Один тон: быстрая атака + экспоненциальное затухание = «дин», не гудок. */
    function tone(freq, delay, dur, peak) {
        if (!ctx) return;

        var at = ctx.currentTime + 0.005 + delay;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, at);
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.linearRampToValueAtTime(peak, at + 0.008);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + dur);

        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(at);
        osc.stop(at + dur + 0.02);
    }

    /**
     * Сигнал состояния. Звонкий, на 1–1,6 кГц: тихие синусы 660/880 Гц звучали
     * глухо (замечание владельца). Верхняя октава даёт ясность, поэтому сигнал
     * слышно и на ноутбучных динамиках, и на гарнитуре.
     */
    function cue(kind) {
        if (!cueOn()) return;
        if (!ctx) return;

        // Контекст мог уснуть (политика автозапуска, фоновая вкладка): без
        // resume() сигнал не прозвучит, и человек не поймёт, что его слушают.
        if (ctx.state === 'suspended' && ctx.resume) ctx.resume();

        if (kind === 'listen') {
            tone(1046.5, 0, 0.16, 0.16);
            tone(1568.0, 0, 0.12, 0.06);
            tone(1318.5, 0.09, 0.18, 0.13);
        } else {
            tone(784.0, 0, 0.14, 0.10);
            tone(587.3, 0.07, 0.16, 0.08);
        }
    }

    // ------------------------------------------------------- микрофон

    /**
     * Микрофон берётся на время слушания и сразу отпускается, когда реплика
     * записана. Это не оптимизация, а лечение звука: пока устройство захвачено,
     * система держит выход в «режиме связи» — и ответ, и любой другой звук
     * страницы становятся приглушёнными (на Bluetooth-гарнитуре особенно).
     */
    function constraints() {
        var echo = bargeOn();

        return {
            echoCancellation: echo,
            noiseSuppression: true,
            autoGainControl: true,
            channelCount: 1
        };
    }

    function ensureCtx() {
        if (ctx) {
            if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
            return;
        }

        var Ctx = window.AudioContext || window.webkitAudioContext;

        if (!Ctx) return;

        ctx = new Ctx();

        if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
    }

    function acquire() {
        if (mic) return Promise.resolve(true);
        if (acquiring) return acquiring;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            micError = { name: 'Unsupported' };
            return Promise.resolve(false);
        }

        acquiring = navigator.mediaDevices.getUserMedia({ audio: constraints() }).then(function (stream) {
            mic = stream;
            ensureCtx();

            if (!ctx) return false;

            source = ctx.createMediaStreamSource(stream);
            analyser = ctx.createAnalyser();
            analyser.fftSize = 1024;
            frame = new Float32Array(analyser.fftSize);
            source.connect(analyser);
            acquiring = null;
            micError = null;

            return true;
        }).catch(function (err) {
            acquiring = null;
            micError = err;
            release();

            return false;
        });

        return acquiring;
    }

    /** Отпустить устройство: треки закрываются, анализатор отключается. */
    function release() {
        if (source) {
            try { source.disconnect(); } catch (e) { }
            source = null;
        }

        analyser = null;
        frame = null;

        if (mic) {
            mic.getTracks().forEach(function (track) { try { track.stop(); } catch (e) { } });
            mic = null;
        }

        bargeTicks = 0;
        echoReady = false;
        echoBase = 0;
    }

    function micErrorText() {
        if (micError && micError.name === 'NotAllowedError') {
            return 'Доступ к микрофону запрещён. Разрешите его в настройках сайта или пишите текстом.';
        }

        if (micError && micError.name === 'NotFoundError') {
            return 'Микрофон не найден. Напишите сообщение текстом.';
        }

        if (micError && micError.name === 'Unsupported') {
            return 'Браузер не даёт доступ к микрофону — напишите сообщение текстом.';
        }

        return 'Не удалось включить микрофон. Напишите сообщение текстом.';
    }

    function start() {
        if (S.state !== 'idle') return;

        if (!window.MediaRecorder) {
            setStatus('Браузер не умеет записывать звук — напишите сообщение текстом.');
            return;
        }

        ensureCtx();
        startBtn.disabled = true;
        setStatus('Включаю микрофон…', true);

        acquire().then(function (ok) {
            startBtn.disabled = false;

            if (!ok) {
                setStatus(micErrorText());
                notify(micErrorText());
                return;
            }

            startBtn.classList.add('hidden');
            stopBtn.classList.remove('hidden');

            clearInterval(tickTimer);
            tickTimer = setInterval(tick, TICK_MS);

            listenSoon('listen', 0);
        });
    }

    /**
     * Вернуться к слушанию: микрофон → сигнал → короткая пауза → слушаем.
     * Пауза нужна, чтобы сигнал не попал в запись.
     */
    function listenSoon(kind, delay) {
        S.state = 'listening';
        voicedMs = 0;
        bargeTicks = 0;

        cue(kind);

        S.listenFrom = now() + Math.max(150, Number(delay || 0));
        setStatus('Слушаю… говорите.');
    }

    function stop() {
        S.state = 'idle';

        clearInterval(tickTimer);
        tickTimer = null;

        playbackStop();
        stopClock();

        if (recorder) {
            recorder.onstop = null;
            try { recorder.stop(); } catch (e) { }
            recorder = null;
        }

        chunks = [];
        cue('off');
        release();

        setTimeout(function () {
            if (ctx) {
                try { ctx.close(); } catch (e) { }
                ctx = null;
            }
        }, 200);

        startBtn.classList.remove('hidden');
        startBtn.disabled = false;
        stopBtn.classList.add('hidden');
        setStatus('Разговор закончен — можно начать снова.');
    }

    // ------------------------------------------------------------ детектор

    function level() {
        if (!analyser || !frame) return 0;

        analyser.getFloatTimeDomainData(frame);

        var sum = 0;

        for (var i = 0; i < frame.length; i++) sum += frame[i] * frame[i];

        return Math.sqrt(sum / frame.length);
    }

    function tick() {
        if (!analyser || S.state === 'idle' || S.state === 'thinking') return;

        var rms = level();

        if (S.state === 'speaking') {
            bargeCheck(rms);
            return;
        }

        if (S.state !== 'listening') return;
        if (now() < S.listenFrom || now() < S.cooldown) return;

        var isVoice = rms > Math.max(0.014, noiseFloor * 3);

        if (!isVoice && !recorder) {
            noiseFloor = noiseFloor * 0.96 + rms * 0.04;
        }

        if (!recorder) {
            if (isVoice) startSegment();
            return;
        }

        var length = now() - segStart;

        if (isVoice) {
            voicedMs += TICK_MS;
            lastVoice = now();
        }

        // Начали запись на щелчке: голоса не набралось — выбрасываем, не отправляя.
        if (voicedMs < Number(cfg.min_voiced_ms || 300) && length > 900) {
            cancelSegment();
            return;
        }

        if (voicedMs >= Number(cfg.min_voiced_ms || 300)
            && now() - lastVoice > Number(cfg.silence_ms || 800)) {
            stopSegment();
            return;
        }

        if (length > Number(cfg.max_ms || 30000)) stopSegment();
    }

    /**
     * Перебивание во время ответа — только если человек сам его включил.
     *
     * В этом режиме микрофон остаётся открытым, поэтому звук может становиться
     * глуше (см. шапку файла) — это осознанный выбор человека, и он подписан
     * рядом с галочкой. Порог строится от измеренного эха первых 0,3 с ответа,
     * плюс голос должен длиться ~0,4 с: кашель ответ не перебьёт.
     */
    function bargeCheck(rms) {
        if (!bargeOn()) return;

        if (!echoReady) {
            if (!audio || audio.paused) return;

            if (audio.currentTime >= 0.3) {
                echoBase = Math.max(noiseFloor * 3, rms);
                echoReady = true;
            }

            return;
        }

        if (rms > Math.max(0.025, echoBase * 2.2)) {
            bargeTicks += 1;
        } else {
            bargeTicks = 0;
        }

        if (bargeTicks < Number(cfg.barge_ticks || 7)) return;

        playbackStop();
        bargeTicks = 0;
        S.state = 'listening';
        S.listenFrom = 0;
        startSegment();
    }

    function startSegment() {
        if (!mic || recorder) return;

        chunks = [];
        voicedMs = 0;
        segStart = now();
        lastVoice = now();

        var list = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        var mime = '';

        if (window.MediaRecorder && window.MediaRecorder.isTypeSupported) {
            for (var i = 0; i < list.length; i++) {
                if (window.MediaRecorder.isTypeSupported(list[i])) { mime = list[i]; break; }
            }
        }

        try {
            recorder = mime
                ? new MediaRecorder(mic, { mimeType: mime, audioBitsPerSecond: 32000 })
                : new MediaRecorder(mic);
        } catch (e) {
            recorder = null;
            setStatus('Запись не запустилась. Напишите сообщение текстом.');
            return;
        }

        recorder.ondataavailable = function (event) {
            if (event.data && event.data.size) chunks.push(event.data);
        };

        recorder.start(200);

        setStatus('Слышу вас…');
    }

    function cancelSegment() {
        if (!recorder) return;

        var rec = recorder;
        recorder = null;
        chunks = [];

        rec.onstop = null;

        try { rec.stop(); } catch (e) { }

        setStatus('Слушаю… говорите.');
    }

    function stopSegment() {
        if (!recorder) return;

        var rec = recorder;
        recorder = null;

        rec.onstop = function () {
            var type = rec.mimeType || 'audio/webm';
            var blob = new Blob(chunks, { type: type });
            var said = voicedMs;

            chunks = [];

            if (blob.size < 1200 || said < Number(cfg.min_voiced_ms || 300)) {
                S.state = 'listening';
                setStatus('Не расслышал — говорите чуть громче.');
                S.listenFrom = now() + 600;
                return;
            }

            sendHear(blob, type, said);
        };

        try { rec.stop(); } catch (e) { S.state = 'listening'; }
    }

    // ------------------------------------------------------------- сеть

    function postJSON(url, payload) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false, error: 'Сервер ответил не по делу.' }; });
        });
    }

    function extOf(mime) {
        if (mime.indexOf('ogg') >= 0) return 'ogg';
        if (mime.indexOf('mp4') >= 0) return 'm4a';
        if (mime.indexOf('mpeg') >= 0) return 'mp3';
        return 'webm';
    }

    function sendHear(blob, type, voicedMs) {
        S.state = 'thinking';
        setStatus('Распознаю…', true);
        startClock();

        // Реплика записана — устройство отпускаем ДО распознавания и озвучки,
        // чтобы ответ звучал полным голосом, а не «по телефону».
        release();

        var form = new FormData();
        form.append('csrf', cfg.csrf);
        form.append('voiced_ms', String(Math.round(voicedMs || 0)));
        form.append('audio', blob, 'speech.' + extOf(type));

        fetch(cfg.url_hear, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': cfg.csrf },
            body: form
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false, error: 'Сервер ответил не по делу.' }; });
        }).then(function (data) {
            stopClock();

            if (!alive()) return;

            if (!data || !data.ok) {
                resumeAfterError((data && data.error) || 'Не расслышал — скажите ещё раз.', data && data.noise);
                return;
            }

            var text = String(data.text || '');

            // Ответа микрофон тоже слышит: если распознанное — кусок только что
            // прочитанного ответа, это эхо, и в разговор его пускать нельзя.
            if (looksLikeEcho(text)) {
                resumeListening(400);
                return;
            }

            addBubble('user', text, 'распознано · ' + seconds(data.ms || 0));
            sendReply(text, data.ms || 0);
        }).catch(function () {
            stopClock();

            if (!alive()) return;

            resumeAfterError('Связь прервалась — скажите ещё раз.');
        });
    }

    function resumeAfterError(message, silent) {
        setStatus(message);
        if (!silent) notify(message);
        resumeListening(900);
    }

    /** Вернуть микрофон и слушание; вызывается после ответа и после сбоев. */
    function resumeListening(delay) {
        if (!alive()) return;

        S.state = 'listening';
        S.cooldown = now() + Math.max(0, Number(delay || 0));

        setTimeout(function () {
            if (!alive() || S.state === 'speaking') return;

            acquire().then(function (ok) {
                if (!alive()) { release(); return; }

                if (!ok) {
                    S.state = 'idle';
                    startBtn.classList.remove('hidden');
                    stopBtn.classList.add('hidden');
                    setStatus(micErrorText());
                    return;
                }

                listenSoon('listen', 220);
            });
        }, Math.max(0, Number(delay || 0)));
    }

    function sendReply(text, sttMs) {
        setStatus('Думаю…', true);
        startClock();

        if (textInput) textInput.value = '';

        postJSON(cfg.url_reply, {
            csrf: cfg.csrf,
            talk: S.talk,
            text: text,
            stt_ms: sttMs || 0
        }).then(function (data) {
            stopClock();

            if (!alive()) return;

            if (!data || !data.ok) {
                resumeAfterError((data && data.error) || 'Модель не ответила.');
                return;
            }

            S.talk = data.talk || S.talk;
            lastAnswer = String(data.reply || '');

            addBubble('bot', data.reply, 'ответ · ' + seconds(data.llm_ms || 0));

            // Перебивание требует открытого микрофона во время ответа; без него
            // микрофон закрыт — и озвучка звучит полным голосом.
            if (bargeOn()) {
                acquire().then(function () { speakParts(data.parts || []); });
            } else {
                speakParts(data.parts || []);
            }
        }).catch(function () {
            stopClock();

            if (!alive()) return;

            resumeAfterError('Связь прервалась.');
        });
    }

    function fetchSay(text) {
        return fetch(cfg.url_say, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
            body: JSON.stringify({ csrf: cfg.csrf, text: text, voice: S.voice })
        }).then(function (response) {
            if (!response.ok) return null;
            return response.blob();
        }).catch(function () { return null; });
    }

    // ------------------------------------------------------------- озвучка

    function speakParts(parts) {
        queue = (parts || []).filter(function (part) { return String(part).trim() !== ''; });
        pending = {};

        if (!queue.length) {
            finishSpeaking(400);
            return;
        }

        S.state = 'speaking';
        setStatus(bargeOn() ? 'Говорю… перебейте голосом.' : 'Говорю…', true);

        playNext();
    }

    function playNext() {
        var text = queue.shift();

        if (!text) {
            finishSpeaking(500);
            return;
        }

        // Следующая часть заказывается сразу: пока играет эта, она уже готова.
        if (queue.length && !pending[queue[0]]) pending[queue[0]] = fetchSay(queue[0]);

        var request = pending[text] || fetchSay(text);
        delete pending[text];

        request.then(function (blob) {
            if (S.state !== 'speaking') return;

            if (!blob) { playNext(); return; }

            playBlob(blob, playNext);
        }).catch(function () { playNext(); });
    }

    function playBlob(blob, onEnd) {
        stopAudioElement();

        audioUrl = URL.createObjectURL(blob);
        audio = new Audio(audioUrl);
        audio.preload = 'auto';

        var done = function () {
            stopAudioElement();
            if (onEnd) onEnd();
        };

        audio.onended = done;
        audio.onerror = done;

        audio.play().catch(function () {
            // Автозапуск запрещён (политика браузера): предупреждаем, а не молчим.
            notify('Браузер не дал включить звук: нажмите на страницу и повторите.');
            done();
        });
    }

    function stopAudioElement() {
        if (audio) {
            try { audio.pause(); } catch (e) { }
            audio.onended = null;
            audio.onerror = null;
            audio = null;
        }

        if (audioUrl) {
            try { URL.revokeObjectURL(audioUrl); } catch (e) { }
            audioUrl = '';
        }
    }

    function playbackStop() {
        queue = [];
        pending = {};
        stopAudioElement();
    }

    /**
     * Ответ закончился: пауза на затухание колонок, затем берём микрофон,
     * сигнал и слушаем. Без паузы микрофон ловит хвост последней фразы.
     */
    function finishSpeaking(delay) {
        if (!alive()) return;

        S.state = 'listening';
        stopAudioElement();

        // В режиме перебивания микрофон был открыт — закрываем, чтобы не
        // глушить звук и не ловить эхо, потом берём заново.
        release();

        resumeListening(Math.max(400, Number(delay || 0)));
    }

    // ------------------------------------------------------------- эхо

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/ё/g, 'е')
            .replace(/[^\p{L}\p{N} ]+/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function looksLikeEcho(text) {
        var said = normalize(text);
        var answer = normalize(lastAnswer);

        if (!answer || said.length < 12) return false;
        if (answer.indexOf(said) !== -1) return true;

        var words = said.split(' ').filter(function (w) { return w.length > 2; });

        if (words.length < 3) return false;

        var hits = words.filter(function (w) { return answer.indexOf(w) !== -1; }).length;

        return hits / words.length >= 0.8;
    }

    // ------------------------------------------------------------- события

    startBtn.addEventListener('click', start);
    stopBtn.addEventListener('click', stop);

    if (newBtn) {
        newBtn.addEventListener('click', function () {
            S.talk = '';
            lastAnswer = '';
            playbackStop();

            while (log.firstChild) log.removeChild(log.firstChild);

            var item = document.createElement('li');
            item.className = 'text-sm text-gray-500 dark:text-gray-400';
            item.textContent = 'Новая тема. Говорите — контекст прошлого разговора не тянем.';
            log.appendChild(item);

            if (S.state === 'idle') {
                setStatus('Новая тема. Включите разговор или напишите сообщение.');
            } else {
                resumeListening(200);
            }

            if (textInput) textInput.focus();
        });
    }

    if (textForm) {
        textForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var text = (textInput && textInput.value || '').trim();

            if (!text) return;

            if (S.state === 'thinking' || S.state === 'speaking') {
                notify('Дождитесь ответа — это одна-две секунды.');
                return;
            }

            playbackStop();
            release();
            addBubble('user', text, '');
            S.state = 'thinking';
            sendReply(text, 0);
        });
    }

    if (voiceSel) {
        voiceSel.addEventListener('change', function () {
            S.voice = voiceSel.value;

            if (S.state === 'speaking') {
                playbackStop();
                finishSpeaking(400);
            }
        });
    }

    // Галочки читаются в момент использования: память настроек (prefs_js)
    // восстанавливает их после разбора страницы, а не до.
    if (bargeBox) {
        bargeBox.addEventListener('change', function () {
            bargeTicks = 0;

            if (S.state === 'speaking' && !bargeBox.checked) {
                release();
            }
        });
    }

    if (S.talk) {
        setStatus('Разговор продолжен: включите микрофон или напишите продолжение.');
    } else {
        setStatus('Микрофон выключен — нажмите «Начать разговор».');
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && S.state !== 'idle') stop();
    });

    // Свернули вкладку — слушать в фоне незачем: закрываем микрофон и ответ,
    // разговор остаётся, человек продолжит кнопкой.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden && S.state !== 'idle') {
            playbackStop();
            cancelSegment();
            release();
            clearInterval(tickTimer);
            tickTimer = null;
            S.state = 'idle';
            startBtn.classList.remove('hidden');
            stopBtn.classList.add('hidden');
            setStatus('Разговор на паузе: вкладка была скрыта. Нажмите «Начать разговор».');
        }
    });
})();
</script>
