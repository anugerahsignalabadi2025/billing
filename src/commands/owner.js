const config = require('../config');
const plugins = require('../utils/PluginLoader');
const { getDevice } = require('@whiskeysockets/baileys');
const fs = require('fs');
const path = require('path');
const billingBot = require('../utils/billingBot');

const handler = async (m) => {

    const { command, Hanz, isOwner, isSuperOwner, isGroup, msg, senderNumber, pushname } = m;
    const p = config.prefix;
    const nomorUser = senderNumber;

    switch (command.name) {
        case 'ownermenu':
            const device = getDevice(msg.key.id);
            const ownerCmds = (plugins.commandsByFile()['owner'] || [])
            .filter(cmd => !['ownermenu'].includes(cmd));
            const role = isSuperOwner ? 'Super Owner' : (isOwner ? 'Co-Owner' : 'User biasa');
            let menu = `┌─❖「 𝗜𝗡𝗙𝗢 𝗨𝗦𝗘𝗥 」
│● 𝘕𝘢𝘮𝘢: ${pushname}
│● 𝘕𝘰𝘮𝘰𝘳: ${nomorUser}
│● 𝘚𝘵𝘢𝘵𝘶𝘴: ${role}
│● 𝘗𝘦𝘳𝘢𝘯𝘨𝘬𝘢𝘵: ${device}
│
└┬❖ 
┌┤𝖧𝖺𝗒 𝗄𝖺𝗄 ${pushname} 👋
│└────────────┈ ⳹
│「 𝗢𝗪𝗡𝗘𝗥 𝗠𝗘𝗡𝗨 」
│
${ownerCmds.map(cmd => `│⪩ \`${p}${cmd}\``).join('\n')}
│
└────────────┈ ⳹`
            await m.sendInteractive({
                text: menu,
                footer: config.footerTxt,
                quoted: m.fakeOrder,
                contextInfo: {
                    mentionedJid: ["0@s.whatsapp.net"],
                    forwardingScore: 111,
                    isForwarded: true
                },
                buttons: [
                    { name: 'quick_reply', buttonParamsJson: JSON.stringify({ display_text: 'Kembali ke Menu', id: 'menu' }) },
                    { name: 'single_select', buttonParamsJson: JSON.stringify({
                            title: '『 Simpel Menu 』',
                            sections: [{
                                title: '『 Simpel Menu 』',
                                highlight_label: "",
                                rows: [{ title: "General Menu", description: "Select to display general menu", id: "generalmenu" }]
                            }, {
                                highlight_label: "",
                                rows: [{ title: "Owner Menu", description: "Select to display owner menu", id: "ownermenu" }]
                            }, {
                                highlight_label: "",
                                rows: [{ title: "Ffmpeg Menu", description: "Select to display ffmpeg menu", id: "ffmpegmenu" }]
                            }, {
                                highlight_label: "",
                                rows: [{ title: "Downloader Menu", description: "Select to display downloader menu", id: "downloadmenu" }]
                            }, {
                                highlight_label: "",
                                rows: [{ title: "Tools Menu", description: "Select to display tools menu", id: "toolsmenu" }]
                            }, {
                                highlight_label: "Khusus Owner Utama",
                                rows: [{ title: "JadiBot Menu", description: "Select to display jadi bot menu", id: "jadibotmenu" }]
                            }, {
                                highlight_label: "",
                                rows: [{ title: "Fun Menu", description: "Select to display fun menu", id: "funmenu" }]
                            }, {
                                highlight_label: "",
                                rows: [{ title: "Group Menu", description: "Select to display group menu ", id: "groupmenu" }]
                            }]
                        })
                    }]
            });
            break;
case 'addnomor': {
            if (!isSuperOwner) return m.reply({ text: '❌ Khusus Super Owner!' });
            let nomorInput = command.fullArgs.replace(/\D/g, '');

            if (!nomorInput) {
                return m.reply({
                    text: `❌ *Format Salah!*\n\nFormat: \`${p}addnomor <nomor>\`\nContoh: \`${p}addnomor 628123456789\` atau \`${p}addnomor 08123456789\``
                });
            }

            if (nomorInput.startsWith('0')) {
                nomorInput = '62' + nomorInput.slice(1);
            }

            const jidTarget = nomorInput + '@s.whatsapp.net';
            const jsonPath = path.join(process.cwd(), 'src/database/users.json');

            let usersData = [];
            try {
                if (fs.existsSync(jsonPath)) {
                    const fileContent = fs.readFileSync(jsonPath, 'utf8');
                    usersData = JSON.parse(fileContent);
                    if (!Array.isArray(usersData)) usersData = [];
                }
            } catch (err) {
                console.error("Gagal membaca users.json:", err);
            }

            // Cek apakah nomor sudah ada di database
            const isExist = usersData.some(item => {
                const existingJid = typeof item === 'string' ? item : (item.jid || item.nomor);
                return existingJid && existingJid.includes(nomorInput);
            });

            if (isExist) {
                return m.reply({ text: `⚠️ Nomor *${nomorInput}* sudah terdaftar di dalam database \`users.json\`!` });
            }

            usersData.push(jidTarget);

            try {
                const dir = path.dirname(jsonPath);
                if (!fs.existsSync(dir)) {
                    fs.mkdirSync(dir, { recursive: true });
                }
                fs.writeFileSync(jsonPath, JSON.stringify(usersData, null, 2), 'utf8');
                return m.reply({ text: `✅ Berhasil menambahkan nomor *${nomorInput}* ke \`src/database/users.json\`!\nTotal user saat ini: ${usersData.length}` });
            } catch (err) {
                console.error("Gagal menyimpan users.json:", err);
                return m.reply({ text: `❌ Terjadi kesalahan saat menyimpan data ke database.` });
            }
        }

        // FITUR: broadcast (Diperbaiki path-nya menggunakan process.cwd())
        case 'broadcast':
        case 'bc': {
            if (!isOwner) return m.reply({ text: '❌ Khusus Super Owner!' });

            const args = command.fullArgs.trim().split(' ');
            let jsonFileName = 'users.json';
            let broadcastText = '';

            if (args.length >= 2) {
                const possibleJson = args[args.length - 1];
                if (possibleJson.endsWith('.json') || fs.existsSync(path.join(process.cwd(), `src/database/${possibleJson}`))) {
                    jsonFileName = possibleJson;
                    args.pop();
                    broadcastText = args.join(' ');
                } else {
                    broadcastText = command.fullArgs;
                }
            } else {
                broadcastText = command.fullArgs;
            }

            if (!broadcastText) {
                return m.reply({
                    text: `❌ *Format Salah!*\n\nFormat: \`${p}broadcast <pesan> [nama_json]\`\nContoh: \`${p}broadcast Halo semuanya users.json\``
                });
            }

            const jsonPath = path.join(process.cwd(), `src/database/${jsonFileName}`);

            if (!fs.existsSync(jsonPath)) {
                return m.reply({ text: `❌ File database JSON \`${jsonFileName}\` tidak ditemukan di direktori \`src/database/\`!` });
            }

            let targetList;
            try {
                const data = fs.readFileSync(jsonPath, 'utf8');
                targetList = JSON.parse(data);
            } catch (err) {
                console.error("Gagal membaca file JSON broadcast:", err);
                return m.reply({ text: `❌ Terjadi kesalahan saat memparsing file JSON list nomor.` });
            }

            if (!Array.isArray(targetList)) {
                return m.reply({ text: `❌ Format data pada file JSON tersebut bukan berupa array!` });
            }

            if (targetList.length < 10) {
                return m.reply({ text: `❌ Gagal mengirim broadcast! Minimal mengirim pesan untuk 10 nomor. Jumlah nomor saat ini di \`${jsonFileName}\` hanya *${targetList.length}* nomor.` });
            }

            await m.reply({ text: `⏳ Sedang mengirim broadcast ke *${targetList.length}* nomor dari \`${jsonFileName}\`.\n⏱️ Delay per nomor: *45 detik*.\nMohon tunggu proses ini selesai...` });

            let sukses = 0;
            let gagal = 0;

            for (let i = 0; i < targetList.length; i++) {
                const target = targetList[i];
                const jid = typeof target === 'string' 
                    ? (target.includes('@') ? target : target.replace(/\D/g, '') + '@s.whatsapp.net') 
                    : (target.jid || target.nomor);

                if (!jid) continue;

                try {
                    await Hanz.sendMessage(jid, { 
                        text: `📢 ${broadcastText}\n\n${config.footerTxt}` 
                    });
                    sukses++;
                    
                    if (i < targetList.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, 45000));
                    }
                } catch (err) {
                    console.error(`Gagal mengirim broadcast ke ${jid}:`, err);
                    gagal++;
                }
            }

            await m.reply({
                text: `✅ *BROADCAST SELESAI*\n\n` +
                      `• *File Target*: \`${jsonFileName}\`\n` +
                      `• *Berhasil terkirim*: ${sukses}\n` +
                      `• *Gagal terkirim*: ${gagal}\n` +
                      `• *Total Target*: ${targetList.length}`
            });
            break;
        }
        case 'getiduser':
        case 'iduser':
        case 'cekno': {
            if (!isSuperOwner) return m.reply({ text: '❌ Khusus Super Owner!' });
            let nomorInput = command.fullArgs.replace(/\D/g, '');

            if (!nomorInput) {
                return m.reply({
                    text: `❌ *Format Salah!*\n\nFormat: \`${p}getiduser <nomor-hp>\`\nContoh: \`${p}getiduser 0857123xxxxx\` atau \`${p}getiduser 6212345xxxxx\``
                });
            }

            if (nomorInput.startsWith('0')) {
                nomorInput = '62' + nomorInput.slice(1);
            }

            try {
                const [result] = await Hanz.onWhatsApp(nomorInput);

                if (!result || !result.exists) {
                    return m.reply({ text: `❌ Nomor *${nomorInput}* tidak terdaftar di WhatsApp.` });
                }

                const jidKlasik = result.jid;
                const lid = result.lid || "Tidak tersedia";

                let hasil = `🔍 *DATA USER WHATSAPP* 🔍\n\n`;
                hasil += ` • *Nomor Asli* : +${nomorInput}\n`;
                hasil += ` • *JID Klasik* : \`${jidKlasik}\`\n`;
                hasil += ` • *LID Privasi* : \`${lid}\``;

                await m.sendInteractive({
                    text: hasil,
                    footer: config.footerTxt,
                    quoted: m.msg,
                    buttons: [
                        { name: 'cta_copy', buttonParamsJson: JSON.stringify({ display_text: 'Copy JID Klasik', copy_code: jidKlasik }) },
                        { name: 'cta_copy', buttonParamsJson: JSON.stringify({ display_text: 'Copy LID', copy_code: lid }) },
                    ]
                });
            } catch (error) {
                console.error("Gagal melacak nomor:", error);
                await m.reply({ text: `❌ Terjadi kesalahan saat memeriksa nomor tersebut.` });
            }
            break;
        }

        case 'getidch':
        case 'idch':
        case 'cekchannel': {
            if (!isSuperOwner) return m.reply({ text: '❌ Khusus Super Owner!' });
            const textInput = command.fullArgs;
            const channelRegex = /whatsapp\.com\/channel\/([a-zA-Z0-9]+)/i;

            if (!textInput || !channelRegex.test(textInput)) {
                return m.reply({
                    text: `❌ *Format Salah!*\n\nFormat: \`${p}getidch <link-channel>\`\nContoh: \`${p}getidch https://whatsapp.com/channel/0029VaXXXXX\``
                });
            }

            const match = textInput.match(channelRegex);
            const inviteCode = match[1];

            try {
                const metadata = await Hanz.newsletterMetadata("invite", inviteCode);
                const jidAsli = metadata.id;
                const metaDataThread = metadata.thread_metadata || {};
                const namaChannel = metaDataThread.name?.text || "Tidak diketahui";
                const totalPengikut = metaDataThread.subscribers_count || "0";
                const deskripsi = metaDataThread.description?.text || "Tidak ada deskripsi.";

                let hasil = `🔍 *DATA WHATSAPP CHANNEL* 🔍\n\n`;
                hasil += ` • *Nama Channel* : ${namaChannel}\n`;
                hasil += ` • *ID Internal* : \`${jidAsli}\`\n`;
                hasil += ` • *Followers* : ${totalPengikut} pengikut\n`;
                hasil += ` • *Deskripsi* : ${deskripsi}`;

                await m.sendInteractive({
                    text: hasil,
                    footer: config.footerTxt,
                    quoted: m.msg,
                    buttons: [
                        { name: 'cta_copy', buttonParamsJson: JSON.stringify({ display_text: 'Copy ID Channel', copy_code: jidAsli }) },
                    ]
                });
            } catch (error) {
                console.error("Gagal melacak channel:", error);
                await m.reply({
                    text: `❌ *Gagal Mendapatkan Data!*\n\nPastikan link channel valid, publik, dan bot sedang tidak terkena limit query.`
                });
            }
            break;
        }
// Tambahkan di dalam handler switch-case untuk cek detail sender saat ini
case 'myid': {
    return m.reply({
        text: `📱 *DETAIL AKSES ANDA*\n\n` +
              `• Nomor Pengirim: \`${senderNumber}\`\n` +
              `• Status SuperOwner: *${isSuperOwner ? 'YA' : 'TIDAK'}*\n` +
              `• Daftar SuperOwner: \`${JSON.stringify(config.superOwner)}\``
    });
}
        case 'setmode':
        case 'mode': {
            if (!isSuperOwner) return m.reply({ text: '❌ Khusus Super Owner!' });
            const modeInput = command.fullArgs.trim().toLowerCase();

            if (!['public', 'self'].includes(modeInput)) {
                return m.sendInteractive({
                    text: `*MODE BOT SAAT INI:* ${(global.botMode || config.botMode).toUpperCase()}\n\n` +
                        `• *public* → semua orang bisa pakai bot\n` +
                        `• *self* → hanya owner yang bisa pakai bot`,
                    footer: config.footerTxt,
                    quoted: m.msg,
                    buttons: [
                        { name: 'quick_reply', buttonParamsJson: JSON.stringify({ display_text: 'Set Public', id: 'setmode public' }) },
                        { name: 'quick_reply', buttonParamsJson: JSON.stringify({ display_text: 'Set Self', id: 'setmode self' }) },
                    ]
                });
            }

            if (modeInput === (global.botMode || config.botMode)) {
                return m.reply({ text: `Mode bot sudah dalam mode *${modeInput.toUpperCase()}*.` });
            }
            global.botMode = modeInput;
            try {
                const configPath = require('path').join(__dirname, '..', 'config.js');
                let configFile = require('fs').readFileSync(configPath, 'utf8');
                configFile = configFile.replace(/botMode:\s*'(public|self)'/, `botMode: '${modeInput}'`);
                require('fs').writeFileSync(configPath, configFile, 'utf8');
            } catch (e) { }

            await m.reply({
                text: `Mode bot berhasil diubah ke *${modeInput.toUpperCase()}*!\n\n` +
                    `${modeInput === 'self' ? 'Sekarang hanya owner yang bisa pakai bot.' : 'Sekarang semua orang bisa pakai bot.'}`
            });
            break;
        }

        case 'web':
        case 'billing': {
            if (!isOwner) return m.reply({ text: '❌ Fitur data billing hanya untuk owner.' });
            if (isGroup) return m.reply({ text: '❌ Gunakan perintah billing melalui chat pribadi dengan bot.' });

            const args = command.args;
            const action = (args[0] || 'help').toLowerCase();
            const helpText = `*MENU BILLING WEB*\n\n` +
                `• \`${p}web api\` — cek koneksi API\n` +
                `• \`${p}web list <pelanggan|tagihan|tetap|takterduga>\`\n` +
                `• \`${p}web unpaid\` — daftar tagihan belum lunas/cicilan\n` +
                `• \`${p}web get <data> <id>\`\n` +
                `• \`${p}web create <data> <JSON>\`\n` +
                `• \`${p}web update <data> <id> <JSON>\`\n` +
                `• \`${p}web payment <id-tagihan> <jumlah-dibayar>\`\n` +
                `• \`${p}web delete <data> <id> KONFIRMASI\`\n` +
                `• \`${p}web remind\` — pratinjau broadcast tagihan\n` +
                `• \`${p}web remind send\` — kirim pengingat manual\n` +
                `• \`${p}web reminder <on|off|status>\`\n\n` +
                `Data pelanggan: nama, notlp, alamat, paket, tanggal_pemasangan.\n` +
                `Data tagihan: nama, notlp, nominal, tanggal, dibayar, dan field omset lain.\n` +
                `Contoh: \`${p}web create pelanggan {"nama":"Budi","notlp":"08123456789","paket":"Internet","tanggal_pemasangan":"2026-01-10"}\`\n` +
                `Semua perubahan dan pengiriman pesan hanya dapat dilakukan Super Owner.`;

            if (['help', 'menu'].includes(action)) {
                return m.reply({ text: helpText });
            }
            if (['api', 'status-api'].includes(action)) {
                try {
                    const result = await billingBot.requestApi({
                        entity: 'customers',
                        query: { limit: 1 },
                    });
                    return m.reply({
                        text: `✅ API billing terhubung melalui HTTPS.\nPelanggan terbaca: ${result.total}`
                    });
                } catch (error) {
                    return m.reply({ text: `❌ Koneksi API gagal: ${error.message}` });
                }
            }

            if (action === 'reminder') {
                if (!isSuperOwner) return m.reply({ text: '❌ Pengaturan pengingat hanya untuk Super Owner.' });
                const mode = (args[1] || 'status').toLowerCase();
                if (mode === 'status') {
                    try {
                        const settings = billingBot.getReminderSettings();
                        return m.reply({
                            text: `Pengingat otomatis: *${settings.enabled ? 'AKTIF' : 'NONAKTIF'}*\n` +
                                `Pemeriksaan: tanggal jatuh tempo, satu kali per tagihan.\n` +
                                `Zona waktu: ${process.env.BILLING_REMINDER_TIMEZONE || 'Asia/Jakarta'}`
                        });
                    } catch (error) {
                        return m.reply({ text: `❌ Gagal membaca pengaturan pengingat: ${error.message}` });
                    }
                }
                if (!['on', 'off'].includes(mode)) {
                    return m.reply({ text: `Format: \`${p}web reminder <on|off|status>\`` });
                }
                try {
                    if (mode === 'on') {
                        await billingBot.requestApi({ entity: 'invoices', query: { limit: 1 } });
                    }
                    billingBot.setRemindersEnabled(mode === 'on');
                    return m.reply({
                        text: `✅ Pengingat otomatis ${mode === 'on' ? 'diaktifkan' : 'dinonaktifkan'}.`
                    });
                } catch (error) {
                    return m.reply({ text: `❌ Gagal menyimpan pengaturan pengingat: ${error.message}` });
                }
            }

            if (action === 'remind') {
                if (!isSuperOwner) return m.reply({ text: '❌ Pengiriman pengingat hanya untuk Super Owner.' });
                try {
                    const result = await billingBot.requestApi({
                        entity: 'invoices',
                        query: { status: 'unpaid' },
                    });
                    const invoices = result.data;
                    if (!Array.isArray(invoices)) throw new Error('Respons API tagihan tidak valid.');
                    if (args[1]?.toLowerCase() !== 'send') {
                        const preview = invoices.slice(0, 10).map(invoice =>
                            `• ${invoice.nama || 'Tanpa nama'} — ${invoice.status_pembayaran}, sisa ${billingBot.formatRupiah(invoice.sisa_tagihan)}`
                        ).join('\n');
                        return m.reply({
                            text: `📋 Ada *${result.total}* tagihan belum lunas/cicilan.\n` +
                                `${preview ? `\n${preview}${result.total > 10 ? '\n…' : ''}\n` : '\n'}\n` +
                                `Kirim \`${p}web remind send\` untuk mengirim pengingat ke nomor pelanggan yang valid.`
                        });
                    }
                    if (result.total > 100) {
                        return m.reply({
                            text: `❌ Ada ${result.total} tagihan terbuka. Batas broadcast aman per proses adalah 100; gunakan pengingat otomatis pada tanggal jatuh tempo.`
                        });
                    }

                    await m.reply({ text: `⏳ Mengirim pengingat ke ${invoices.length} pelanggan dengan jeda 8 detik per pesan.` });
                    let sent = 0;
                    let skipped = 0;
                    let failed = 0;
                    for (const invoice of invoices) {
                        try {
                            const delivered = await billingBot.sendInvoiceReminder(Hanz, invoice);
                            if (delivered) {
                                sent++;
                                await new Promise(resolve => setTimeout(resolve, 8000));
                            } else {
                                skipped++;
                            }
                        } catch (error) {
                            failed++;
                            console.error(`[BILLING-REMINDER] Gagal mengirim pengingat tagihan ${invoice.id}:`, error.message);
                        }
                    }
                    return m.reply({ text: `✅ Broadcast pengingat selesai.\nTerkirim: ${sent}\nDilewati/duplikat: ${skipped}\nGagal: ${failed}` });
                } catch (error) {
                    return m.reply({ text: `❌ Broadcast pengingat gagal: ${error.message}` });
                }
            }

            if (action === 'unpaid') {
                try {
                    const result = await billingBot.requestApi({
                        entity: 'invoices',
                        query: { status: 'unpaid' },
                    });
                    const rows = result.data.slice(0, 20).map(invoice =>
                        `• #${invoice.id} ${invoice.nama || 'Tanpa nama'} (${invoice.notlp || 'tanpa nomor'})\n` +
                        `  ${invoice.status_pembayaran} — sisa ${billingBot.formatRupiah(invoice.sisa_tagihan)}, jatuh tempo ${invoice.tanggal || '-'}`
                    );
                    return m.reply({
                        text: `📋 Tagihan terbuka: ${result.total}\n\n${rows.join('\n') || 'Tidak ada tagihan terbuka.'}` +
                            `${result.total > 20 ? '\n\nMenampilkan 20 data pertama.' : ''}`
                    });
                } catch (error) {
                    return m.reply({ text: `❌ Gagal mengambil tagihan: ${error.message}` });
                }
            }

            if (action === 'payment') {
                if (!isSuperOwner) return m.reply({ text: '❌ Perubahan pembayaran hanya dapat dilakukan Super Owner.' });
                const id = Number(args[1]);
                const paid = Number(args[2]);
                if (!Number.isSafeInteger(id) || id < 1 || !Number.isFinite(paid) || paid < 0) {
                    return m.reply({ text: `Format: \`${p}web payment <id-tagihan> <jumlah-dibayar>\`` });
                }
                try {
                    await billingBot.requestApi({ method: 'PUT', entity: 'invoices', id, body: { dibayar: paid } });
                    return m.reply({ text: `✅ Pembayaran tagihan #${id} diperbarui menjadi ${billingBot.formatRupiah(paid)}.` });
                } catch (error) {
                    return m.reply({ text: `❌ Gagal memperbarui pembayaran: ${error.message}` });
                }
            }

            const resource = billingBot.normalizeResource(args[1]);
            if (!resource) return m.reply({ text: helpText });

            if (action === 'list') {
                try {
                    const result = await billingBot.requestApi({ entity: resource });
                    const rows = result.data.slice(0, 20).map(item => {
                        if (resource === 'customers') {
                            return `• #${item.id} ${item.nama || '-'} | ${item.notlp || '-'} | ${item.paket || '-'}`;
                        }
                        if (resource === 'invoices') {
                            return `• #${item.id} ${item.nama || '-'} | ${item.status_pembayaran} | sisa ${billingBot.formatRupiah(item.sisa_tagihan)} | ${item.tanggal || '-'}`;
                        }
                        return `• #${item.id} ${item.nama_pengeluaran || '-'} | ${billingBot.formatRupiah(item.nominal)} | ${item.tanggal || '-'}`;
                    });
                    return m.reply({
                        text: `📋 Data ${resource}: ${result.total}\n\n${rows.join('\n') || 'Belum ada data.'}` +
                            `${result.total > 20 ? '\n\nMenampilkan 20 data pertama.' : ''}`
                    });
                } catch (error) {
                    return m.reply({ text: `❌ Gagal mengambil data: ${error.message}` });
                }
            }

            if (action === 'get') {
                const id = Number(args[2]);
                if (!Number.isSafeInteger(id) || id < 1) return m.reply({ text: `Format: \`${p}web get ${args[1]} <id>\`` });
                try {
                    const result = await billingBot.requestApi({ entity: resource, id });
                    return m.reply({ text: `📄 Data ${resource} #${id}\n${JSON.stringify(result.data, null, 2)}` });
                } catch (error) {
                    return m.reply({ text: `❌ Gagal mengambil data: ${error.message}` });
                }
            }

            if (['create', 'update', 'delete', 'payment'].includes(action) && !isSuperOwner) {
                return m.reply({ text: '❌ Perubahan data hanya dapat dilakukan Super Owner.' });
            }

            if (action === 'create' || action === 'update') {
                const isUpdate = action === 'update';
                const id = isUpdate ? Number(args[2]) : undefined;
                const payloadText = args.slice(isUpdate ? 3 : 2).join(' ');
                if (isUpdate && (!Number.isSafeInteger(id) || id < 1)) {
                    return m.reply({ text: `Format: \`${p}web update ${args[1]} <id> <JSON>\`` });
                }
                let payload;
                try {
                    payload = JSON.parse(payloadText);
                } catch {
                    return m.reply({ text: `❌ JSON tidak valid. Kirim object JSON, contoh: \`{"nama":"Budi","notlp":"08123456789"}\`` });
                }
                if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                    return m.reply({ text: '❌ Data harus berupa object JSON.' });
                }
                try {
                    const result = await billingBot.requestApi({
                        method: isUpdate ? 'PUT' : 'POST',
                        entity: resource,
                        id,
                        body: payload,
                    });
                    return m.reply({
                        text: `✅ ${isUpdate ? 'Data berhasil diperbarui.' : `Data berhasil dibuat dengan ID #${result.id}.`}`
                    });
                } catch (error) {
                    return m.reply({ text: `❌ Gagal menyimpan data: ${error.message}` });
                }
            }

            if (action === 'delete') {
                const id = Number(args[2]);
                if (!Number.isSafeInteger(id) || id < 1 || args[3]?.toUpperCase() !== 'KONFIRMASI') {
                    return m.reply({ text: `Format: \`${p}web delete ${args[1]} <id> KONFIRMASI\`` });
                }
                try {
                    await billingBot.requestApi({ method: 'DELETE', entity: resource, id });
                    return m.reply({ text: `✅ Data ${resource} #${id} berhasil dihapus.` });
                } catch (error) {
                    return m.reply({ text: `❌ Gagal menghapus data: ${error.message}` });
                }
            }

            return m.reply({ text: helpText });
        }

        case 'info': {
            const u = process.uptime();
            const hari = Math.floor(u / 86400);
            const jam = Math.floor((u % 86400) / 3600);
            const menit = Math.floor((u % 3600) / 60);
            const detik = Math.floor(u % 60);
            await m.reply({
                text: `┌─❖「 𝗜𝗡𝗙𝗢 𝗕𝗢𝗧 」\n` +
                `│\n` +
                `│● 𝕃anama    : ${config.botName}\n` +
                `│● 𝘖𝘸𝘯𝘦𝘳    : ${[].concat(config.superOwner).join(', ')}\n` +
                `│● 𝘗𝘳𝘦𝘧𝘪𝘹  : ${config.prefix}\n` +
                `│● 𝘜𝘱𝘵𝘪𝘮𝘦  : ${hari}h ${jam}j ${menit}m ${detik}d\n` +
                `│● 𝘕𝘰𝘥𝘦    : ${process.version}\n` +
                `│● 𝘗𝘭𝘢𝘵𝘧𝘰𝘳𝘮 : ${process.platform}\n` +
                `│● 𝘔𝘰𝘥𝘦    : ${(global.botMode || config.botMode).toUpperCase()}\n` +
                `│● 𝘊𝘰𝘮𝘮𝘢𝘯𝘥  : ${plugins.commandList().length} fitur aktif\n` +
                `│\n` +
                `└────────────┈ ⳹`
            });
            break;
        }

    }
};

module.exports = handler;
