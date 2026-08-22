import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

const brandAlert = Swal.mixin({
    buttonsStyling: false,
    reverseButtons: true,
    focusCancel: true,
    customClass: {
        popup: 'sppg-swal-popup',
        title: 'sppg-swal-title',
        htmlContainer: 'sppg-swal-content',
        actions: 'sppg-swal-actions',
        confirmButton: 'sppg-swal-confirm',
        cancelButton: 'sppg-swal-cancel',
    },
});

const titleFor = (type) => ({
    success: 'Berhasil',
    error: 'Terjadi kesalahan',
    warning: 'Perlu diperhatikan',
    info: 'Informasi',
    question: 'Konfirmasi',
}[type] || 'Informasi');

const showAlert = ({ type = 'info', title, message = '', timer } = {}) => brandAlert.fire({
    icon: type,
    title: title || titleFor(type),
    text: String(message || ''),
    confirmButtonText: 'Tutup',
    timer: timer ?? (type === 'success' ? 2600 : undefined),
    timerProgressBar: type === 'success',
});

window.SPPGAlert = {
    show: showAlert,
    success: (message, title = 'Berhasil') => showAlert({ type: 'success', title, message }),
    error: (message, title = 'Terjadi kesalahan') => showAlert({ type: 'error', title, message }),
    warning: (message, title = 'Perlu diperhatikan') => showAlert({ type: 'warning', title, message }),
    info: (message, title = 'Informasi') => showAlert({ type: 'info', title, message }),
    confirm: (message, options = {}) => brandAlert.fire({
        icon: options.icon || 'question',
        title: options.title || 'Konfirmasi tindakan',
        text: String(message || 'Lanjutkan tindakan ini?'),
        showCancelButton: true,
        confirmButtonText: options.confirmButtonText || 'Ya, lanjutkan',
        cancelButtonText: options.cancelButtonText || 'Batal',
        focusCancel: true,
    }),
};

const consumedAlerts = new Set();

function consumeAlertMarkers(root = document) {
    const markers = [];

    if (root instanceof Element && root.matches('[data-sppg-alert]')) markers.push(root);
    root.querySelectorAll?.('[data-sppg-alert]').forEach((marker) => markers.push(marker));

    markers.forEach((marker) => {
        const message = marker.dataset.message?.trim();
        if (!message) {
            marker.remove();
            return;
        }

        const fingerprint = `${marker.dataset.type || 'info'}:${message}`;
        marker.remove();
        if (consumedAlerts.has(fingerprint)) return;

        consumedAlerts.add(fingerprint);
        window.setTimeout(() => consumedAlerts.delete(fingerprint), 1500);
        showAlert({
            type: marker.dataset.type || 'info',
            title: marker.dataset.title || undefined,
            message,
        });
    });
}

const confirmationBypass = new WeakSet();

document.addEventListener('click', async (event) => {
    const trigger = event.target.closest?.('[wire\\:confirm]');
    if (!trigger || confirmationBypass.has(trigger)) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const message = trigger.getAttribute('wire:confirm') || 'Lanjutkan tindakan ini?';
    const result = await window.SPPGAlert.confirm(message, {
        icon: /hapus|batalkan|tolak|nonaktif/i.test(message) ? 'warning' : 'question',
        confirmButtonText: /hapus/i.test(message) ? 'Ya, hapus' : 'Ya, lanjutkan',
    });

    if (!result.isConfirmed) return;

    confirmationBypass.add(trigger);
    const nativeConfirm = window.confirm;
    window.confirm = () => true;
    try {
        trigger.click();
    } finally {
        window.confirm = nativeConfirm;
        queueMicrotask(() => confirmationBypass.delete(trigger));
    }
}, true);

document.addEventListener('sppg-alert', (event) => showAlert(event.detail || {}));
window.addEventListener('sppg-alert', (event) => showAlert(event.detail || {}));

document.addEventListener('livewire:init', () => {
    window.Livewire.on('sppg-alert', (payload = {}) => {
        const detail = Array.isArray(payload) ? (payload[0] || {}) : payload;
        showAlert(detail);
    });

    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(({ snapshot }) => {
            try {
                const parsed = typeof snapshot === 'string' ? JSON.parse(snapshot) : snapshot;
                const errors = parsed?.memo?.errors || {};
                const first = Object.values(errors).flat().find(Boolean);
                if (first) showAlert({ type: 'error', title: 'Data belum lengkap', message: first });
            } catch {
                // Respons tanpa snapshot valid bukan respons validasi Livewire.
            }
        });
    });

    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status }) => {
            if (status === 419) {
                showAlert({
                    type: 'warning',
                    title: 'Sesi telah berakhir',
                    message: 'Silakan muat ulang halaman dan masuk kembali sebelum melanjutkan.',
                });
                return;
            }

            if (status >= 500) {
                showAlert({
                    type: 'error',
                    title: 'Proses belum berhasil',
                    message: 'Server mengalami kendala saat memproses permintaan. Silakan coba kembali.',
                });
            }
        });
    });
});

const alertObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
        if (node instanceof Element) consumeAlertMarkers(node);
    }));
});

const startAlerts = () => {
    consumeAlertMarkers();
    alertObserver.observe(document.body, { childList: true, subtree: true });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startAlerts, { once: true });
} else {
    startAlerts();
}

document.addEventListener('livewire:navigated', () => consumeAlertMarkers());
