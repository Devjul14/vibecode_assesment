const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

document.addEventListener('alpine:init', () => {
    /**
     * Angka KPI yang menghitung naik. Mendukung sufiks (mis. "73%") dan menganimasikan ulang saat nilai berubah (poll).
     */
    window.Alpine.data('countUp', (target) => ({
        display: target,
        init() {
            this.animate(target, true);
            new MutationObserver(() => this.animate(this.$el.dataset.value)).observe(this.$el, {
                attributes: true,
                attributeFilter: ['data-value'],
            });
        },
        animate(raw, fromZero = false) {
            const match = String(raw).match(/^(-?[\d.]+)(.*)$/);
            if (!match || prefersReducedMotion()) {
                this.display = raw;
                return;
            }
            const end = parseFloat(match[1]);
            const suffix = match[2];
            const decimals = (match[1].split('.')[1] || '').length;
            const start = fromZero ? 0 : parseFloat(String(this.display)) || 0;
            const started = performance.now();
            const step = (now) => {
                const progress = Math.min(1, (now - started) / 700);
                const eased = 1 - Math.pow(1 - progress, 3);
                this.display = (start + (end - start) * eased).toFixed(decimals) + suffix;
                if (progress < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        },
    }));

    /**
     * Toast: menerima event "toast" dari Livewire (type, message, warnings).
     */
    window.Alpine.data('toaster', (initial = null) => ({
        toasts: [],
        init() {
            if (initial) this.push(initial);
        },
        push(detail) {
            const payload = Array.isArray(detail) ? detail[0] : detail;
            const id = Date.now() + Math.random();
            this.toasts.push({ id, visible: true, ...payload });
            setTimeout(() => this.dismiss(id), payload.type === 'error' ? 8000 : 4500);
        },
        dismiss(id) {
            const toast = this.toasts.find((t) => t.id === id);
            if (toast) toast.visible = false;
            setTimeout(() => (this.toasts = this.toasts.filter((t) => t.id !== id)), 300);
        },
    }));

    /**
     * x-grow="persen": bar tumbuh dari 0 ke lebar target saat masuk viewport.
     */
    window.Alpine.directive('grow', (el, { expression }, { evaluateLater, effect }) => {
        const getWidth = evaluateLater(expression);
        el.classList.add('bar-grow');
        let shown = prefersReducedMotion();
        effect(() =>
            getWidth((width) => {
                if (shown) {
                    el.style.width = `${width}%`;
                    return;
                }
                el.style.width = '0%';
                new IntersectionObserver((entries, observer) => {
                    if (entries[0].isIntersecting) {
                        shown = true;
                        requestAnimationFrame(() => (el.style.width = `${width}%`));
                        observer.disconnect();
                    }
                }).observe(el);
            }),
        );
    });
});
