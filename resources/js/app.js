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
    text: type === 'error' ? readableError(String(message || '')) : String(message || ''),
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

const fieldNames = {
    supplier_id: 'Supplier', ingredient_id: 'Bahan', non_food_item_id: 'Barang non-pangan',
    inventory_lot_id: 'Barang dan lot', photo: 'Foto', photo_path: 'Foto',
    quantity: 'Jumlah', actual_quantity: 'Jumlah fisik aktual',
    received_quantity: 'Jumlah diterima', accepted_quantity: 'Jumlah baik',
    rejected_quantity: 'Jumlah ditolak', measurement_unit_id: 'Satuan',
    route_name: 'Nama rute', reason: 'Alasan', notes: 'Catatan',
    completion: 'Penyelesaian pekerjaan', submission: 'Pengajuan laporan',
    fields: 'Data formulir', items: 'Daftar barang',
    rows_payload: 'Daftar barang', manual_rows_payload: 'Daftar barang',
};

function inputForError(key) {
    return [...document.querySelectorAll('input, select, textarea')].find((input) =>
        input.getAttributeNames().some((name) => name.startsWith('wire:model') && input.getAttribute(name) === key)
        || input.name === key || input.id === key,
    );
}

function labelForError(key, input) {
    const fieldLabel = input?.closest('label')?.querySelector('span')?.textContent?.replace(/\s*\*\s*$/, '').trim();
    const segments = key.split('.');
    const fallback = fieldNames[segments.at(-1)]
        || segments.at(-1).replace(/([a-z])([A-Z])/g, '$1 $2').replaceAll('_', ' ');
    const label = fieldLabel || fallback.charAt(0).toUpperCase() + fallback.slice(1);
    const rowIndex = segments.find((segment) => /^\d+$/.test(segment));
    return rowIndex === undefined ? label : `Baris ${Number(rowIndex) + 1} — ${label}`;
}

function readableError(message) {
    if (/sqlstate|exception|undefined (variable|array)|stack trace|http request returned|status code/i.test(message)) {
        return 'Data belum dapat diproses karena kendala sistem. Coba lagi; jika tetap gagal, hubungi administrator.';
    }
    if (/validation\.[a-z_]+/i.test(message)) {
        return 'Isian ini belum benar. Periksa kembali nilainya.';
    }
    return message;
}

function showValidationErrors(errors) {
    const entries = Object.entries(errors).flatMap(([key, messages]) =>
        (Array.isArray(messages) ? messages : [messages]).filter(Boolean).map((message) => ({
            key, input: inputForError(key), message: String(message),
        })),
    );
    if (!entries.length) return;

    const firstInput = entries.find((entry) => entry.input)?.input;
    entries.forEach((entry) => entry.input?.classList.add('sppg-invalid-field'));
    const lines = entries.slice(0, 5).map((entry) => {
        const label = labelForError(entry.key, entry.input);
        const message = readableError(entry.message);
        const fieldName = label.split(' — ').at(-1);
        const detail = message.toLocaleLowerCase().startsWith(fieldName.toLocaleLowerCase())
            ? message.slice(fieldName.length).trimStart()
            : message;
        return `${label}: ${detail}`;
    });
    if (entries.length > 5) lines.push(`Dan ${entries.length - 5} isian lain yang perlu diperbaiki.`);

    showAlert({ type: 'error', title: 'Periksa isian berikut', message: lines.join('\n') }).then(() => {
        firstInput?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstInput?.focus({ preventScroll: true });
    });
}

document.addEventListener('input', (event) => event.target?.classList?.remove('sppg-invalid-field'), true);
document.addEventListener('change', (event) => event.target?.classList?.remove('sppg-invalid-field'), true);

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

    const validationMarkers = [];
    if (root instanceof Element && root.matches('[data-sppg-validation-errors]')) validationMarkers.push(root);
    root.querySelectorAll?.('[data-sppg-validation-errors]').forEach((marker) => validationMarkers.push(marker));
    validationMarkers.forEach((marker) => {
        const raw = marker.dataset.errors || '{}';
        marker.remove();
        try {
            const errors = JSON.parse(raw);
            if (Object.keys(errors).length) showValidationErrors(errors);
        } catch {
            showAlert({ type: 'error', title: 'Periksa isian', message: 'Ada isian yang belum benar. Periksa kembali formulir.' });
        }
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
                if (Object.keys(errors).length) showValidationErrors(errors);
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
