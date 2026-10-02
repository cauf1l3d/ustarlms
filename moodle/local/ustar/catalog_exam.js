document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-catalog-exam-form]');
    if (!form) {
        return;
    }

    const cards = Array.from(
        form.querySelectorAll('[data-exam-question]')
    );

    if (!cards.length) {
        return;
    }

    let index = Math.max(
        0,
        cards.findIndex(card => card.classList.contains('is-active'))
    );

    const progress = document.querySelector('[data-exam-progress]');
    const progressLabel = document.querySelector('[data-exam-progress-label]');

    function selected(card) {
        return !!card.querySelector('input[type="radio"]:checked');
    }

    function render() {
        cards.forEach((card, i) => {
            card.classList.toggle('is-active', i === index);
        });

        const pct = ((index + 1) / cards.length) * 100;

        if (progress) {
            progress.style.width = pct + '%';
        }

        if (progressLabel) {
            progressLabel.textContent =
                (index + 1) + ' / ' + cards.length;
        }

        window.scrollTo({
            top: Math.max(
                0,
                form.getBoundingClientRect().top
                + window.scrollY
                - 120
            ),
            behavior: 'smooth'
        });
    }

    form.addEventListener('click', function (event) {
        const next = event.target.closest('[data-exam-next]');
        const prev = event.target.closest('[data-exam-prev]');

        if (next) {
            const card = cards[index];

            if (!selected(card)) {
                card.classList.add('has-error');
                return;
            }

            card.classList.remove('has-error');

            if (index < cards.length - 1) {
                index++;
                render();
            }
        }

        if (prev) {
            if (index > 0) {
                index--;
                render();
            }
        }
    });

    form.addEventListener('change', function (event) {
        if (event.target.matches('input[type="radio"]')) {
            const card = event.target.closest('[data-exam-question]');
            if (card) {
                card.classList.remove('has-error');
            }
        }
    });

    form.addEventListener('submit', function (event) {
        const missing = cards.findIndex(card => !selected(card));

        if (missing !== -1) {
            event.preventDefault();
            index = missing;
            cards[index].classList.add('has-error');
            render();
        }
    });

    render();
});
