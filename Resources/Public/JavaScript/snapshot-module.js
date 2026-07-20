// Client interactivity for the Snapshot backend module. Loaded as a JavaScript module via the
// import map so it complies with the backend Content Security Policy.

function initSourceSelection() {
    const cards = document.querySelectorAll('#snapshot-cards .card');
    if (cards.length === 0) {
        return;
    }

    const summary = document.getElementById('snapshot-summary');
    const sub = document.getElementById('snapshot-sub');
    const prepare = document.getElementById('snapshot-prepare');

    const update = () => {
        const selected = Array.from(cards).filter((card) => card.classList.contains('selected'));
        const values = selected.map((card) => card.dataset.source);
        const hasDb = values.includes('db');
        const hasFiles = values.includes('files');

        if (hasDb && hasFiles) {
            summary.textContent = 'Database + Fileadmin';
        } else if (hasDb) {
            summary.textContent = 'Database only';
        } else if (hasFiles) {
            summary.textContent = 'Fileadmin only';
        } else {
            summary.textContent = 'Nothing selected';
        }
        sub.textContent = selected.length === 0 ? 'Pick at least one source' : 'Prepare a secured, single-use download';
        prepare.disabled = selected.length === 0;
    };

    cards.forEach((card) => card.addEventListener('click', () => {
        card.classList.toggle('selected');
        const checkbox = card.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.checked = card.classList.contains('selected');
        }
        update();
    }));

    update();
}

function initCountdown() {
    const el = document.getElementById('snapshot-countdown');
    if (el === null) {
        return;
    }

    let seconds = parseInt(el.dataset.seconds || '0', 10);
    const render = () => {
        if (seconds <= 0) {
            el.textContent = 'expired';
            document.querySelectorAll('.artifact .btn').forEach((btn) => {
                btn.setAttribute('aria-disabled', 'true');
                btn.style.opacity = '0.45';
                btn.style.pointerEvents = 'none';
            });
            return true;
        }
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        el.textContent = `${m}:${s.toString().padStart(2, '0')}`;
        return false;
    };

    if (render()) {
        return;
    }
    const timer = setInterval(() => {
        seconds -= 1;
        if (render()) {
            clearInterval(timer);
        }
    }, 1000);
}

function init() {
    initSourceSelection();
    initCountdown();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
