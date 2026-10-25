require('dotenv').config();

const fs = require('fs');
const path = require('path');
const config = require('../config');

const databaseDir = path.join(__dirname, '..', 'database');
const settingsPath = path.join(databaseDir, 'billing-bot-settings.json');
const reminderStatePath = path.join(databaseDir, 'billing-reminder-state.json');
const resourceAliases = {
    pelanggan: 'customers',
    customer: 'customers',
    customers: 'customers',
    tagihan: 'invoices',
    omset: 'invoices',
    invoice: 'invoices',
    invoices: 'invoices',
    tetap: 'fixed-expenses',
    'pengeluaran-tetap': 'fixed-expenses',
    'fixed-expenses': 'fixed-expenses',
    takterduga: 'unexpected-expenses',
    'pengeluaran-takterduga': 'unexpected-expenses',
    'unexpected-expenses': 'unexpected-expenses',
};

function ensureDirectory() {
    fs.mkdirSync(databaseDir, { recursive: true });
}

function readJson(filePath, fallback) {
    ensureDirectory();
    if (!fs.existsSync(filePath)) return fallback;
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function writeJson(filePath, data) {
    ensureDirectory();
    const tempPath = `${filePath}.${process.pid}.tmp`;
    fs.writeFileSync(tempPath, JSON.stringify(data, null, 2), { encoding: 'utf8', mode: 0o600 });
    fs.renameSync(tempPath, filePath);
}

function getApiConfig() {
    const apiUrl = process.env.BILLING_API_URL;
    const token = process.env.BILLING_API_TOKEN;
    if (!apiUrl || !token) {
        throw new Error('Konfigurasi BILLING_API_URL dan BILLING_API_TOKEN belum diatur.');
    }

    let parsedUrl;
    try {
        parsedUrl = new URL(apiUrl);
    } catch {
        throw new Error('BILLING_API_URL bukan URL yang valid.');
    }
    if (parsedUrl.protocol !== 'https:') {
        throw new Error('API tagihan wajib memakai HTTPS. HTTP tidak diizinkan untuk data pelanggan.');
    }
    if (token.length < 32) {
        throw new Error('BILLING_API_TOKEN harus memiliki panjang minimal 32 karakter.');
    }
    return { parsedUrl, token };
}

async function requestApi({ method = 'GET', entity, id, query = {}, body }) {
    const { parsedUrl, token } = getApiConfig();
    if (entity) parsedUrl.searchParams.set('entity', entity);
    if (id !== undefined && id !== null) parsedUrl.searchParams.set('id', String(id));
    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== null) parsedUrl.searchParams.set(key, String(value));
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
        const response = await fetch(parsedUrl, {
            method,
            headers: {
                Authorization: `Bearer ${token}`,
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
            signal: controller.signal,
        });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || result.status !== 'success') {
            const message = result?.message || `API mengembalikan HTTP ${response.status}.`;
            throw new Error(message);
        }
        return result;
    } catch (error) {
        if (error.name === 'AbortError') {
            throw new Error('Permintaan ke API tagihan melewati batas waktu 15 detik.');
        }
        throw error;
    } finally {
        clearTimeout(timeout);
    }
}

function normalizeResource(resource) {
    return resourceAliases[String(resource || '').toLowerCase()] || null;
}

function getReminderSettings() {
    const settings = readJson(settingsPath, { enabled: false });
    if (typeof settings.enabled !== 'boolean') {
        throw new Error('File pengaturan pengingat tidak valid.');
    }
    return settings;
}

function setRemindersEnabled(enabled) {
    writeJson(settingsPath, { enabled: Boolean(enabled) });
}

function getReminderState() {
    const state = readJson(reminderStatePath, { sent: {} });
    if (!state || typeof state.sent !== 'object' || Array.isArray(state.sent)) {
        throw new Error('File status pengingat tidak valid.');
    }
    return state;
}

function markReminderSent(invoiceId, dueDate) {
    const state = getReminderState();
    state.sent[`${invoiceId}:${dueDate}`] = new Date().toISOString();
    writeJson(reminderStatePath, state);
}

function hasReminderBeenSent(invoiceId, dueDate) {
    const state = getReminderState();
    return Boolean(state.sent[`${invoiceId}:${dueDate}`]);
}

function getJakartaDate() {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: process.env.BILLING_REMINDER_TIMEZONE || 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const dateParts = Object.fromEntries(parts.map(part => [part.type, part.value]));
    return `${dateParts.year}-${dateParts.month}-${dateParts.day}`;
}

function normalizePhoneNumber(phone) {
    let number = String(phone || '').replace(/\D/g, '');
    if (number.startsWith('0')) number = `62${number.slice(1)}`;
    else if (number.startsWith('8')) number = `62${number}`;
    if (!/^62\d{8,13}$/.test(number)) return null;
    return `${number}@s.whatsapp.net`;
}

function formatRupiah(amount) {
    return `Rp${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(amount) || 0)}`;
}

function makeReminderMessage(invoice) {
    const amount = Number(invoice.nominal);
    const paid = Number(invoice.dibayar) || 0;
    const balance = Number(invoice.sisa_tagihan ?? Math.max(0, amount - paid));
    const name = invoice.nama || 'Pelanggan';
    return `Halo ${name}, ini pengingat tagihan internet ASA.\n\n` +
        `• Periode: ${invoice.periode || invoice.tanggal || '-'}\n` +
        `• Jatuh tempo: ${invoice.tanggal || '-'}\n` +
        `• Tagihan: ${formatRupiah(amount)}\n` +
        `• Sudah dibayar: ${formatRupiah(paid)}\n` +
        `• Sisa tagihan: ${formatRupiah(balance)}\n\n` +
        `Jika sudah membayar, mohon abaikan pesan ini. Anda dapat membayar atau mencicil sisa tagihan dengan menghubungi admin ASA.`;
}

async function sendInvoiceReminder(Hanz, invoice, stateDate = getJakartaDate()) {
    const stateKey = `${invoice.id}:${stateDate}`;
    global.billingReminderSending = global.billingReminderSending || new Set();
    if (hasReminderBeenSent(invoice.id, stateDate) || global.billingReminderSending.has(stateKey)) return false;
    const jid = normalizePhoneNumber(invoice.notlp);
    if (!jid) {
        console.warn(`[BILLING-REMINDER] Nomor pelanggan tidak valid untuk tagihan ${invoice.id}.`);
        return false;
    }
    global.billingReminderSending.add(stateKey);
    try {
        await Hanz.sendMessage(jid, { text: makeReminderMessage(invoice) });
        markReminderSent(invoice.id, stateDate);
        return true;
    } finally {
        global.billingReminderSending.delete(stateKey);
    }
}

async function sendDueDateReminders() {
    const settings = getReminderSettings();
    if (!settings.enabled || global.billingReminderRunning) return;

    const mainKey = path.basename(config.authFolder);
    const Hanz = global.conns?.[mainKey];
    if (!Hanz?.user?.id) return;

    global.billingReminderRunning = true;
    try {
        const dueDate = getJakartaDate();
        if (global.billingReminderGenerationDate !== dueDate) {
            await requestApi({
                method: 'POST',
                entity: 'invoices',
                query: { action: 'ensure_due', due: dueDate },
                body: {},
            });
            global.billingReminderGenerationDate = dueDate;
        }
        const result = await requestApi({
            entity: 'invoices',
            query: { status: 'unpaid', due: dueDate },
        });
        for (const invoice of result.data) {
            if (hasReminderBeenSent(invoice.id, dueDate)) continue;
            try {
                await sendInvoiceReminder(Hanz, invoice, dueDate);
                await new Promise(resolve => setTimeout(resolve, 8000));
            } catch (error) {
                console.error(`[BILLING-REMINDER] Gagal mengirim pengingat tagihan ${invoice.id}:`, error.message);
            }
        }
    } finally {
        global.billingReminderRunning = false;
    }
}

if (!global.billingReminderTimer) {
    global.billingReminderTimer = setInterval(() => {
        sendDueDateReminders().catch(error => {
            console.error('[BILLING-REMINDER] Pemeriksaan jatuh tempo gagal:', error.message);
        });
    }, 60 * 1000);
    global.billingReminderTimer.unref?.();
}

module.exports = {
    requestApi,
    normalizeResource,
    getReminderSettings,
    setRemindersEnabled,
    getJakartaDate,
    normalizePhoneNumber,
    formatRupiah,
    makeReminderMessage,
    sendInvoiceReminder,
};
