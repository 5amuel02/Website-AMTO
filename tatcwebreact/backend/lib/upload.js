import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';

// Maximum allowed upload size: 5 MB
export const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

// Whitelist of safe extensions and their corresponding MIME signatures
export const ALLOWED_EXTENSIONS = new Set(['.pdf', '.png', '.jpg', '.jpeg', '.webp']);

export const ALLOWED_MIME_TYPES = new Set([
  'application/pdf',
  'image/png',
  'image/jpeg',
  'image/webp'
]);

/**
 * [SEC-TATC-01] DP-06 & APP-06: Deteksi Magic Bytes (File Signature Biner) asli dari Buffer
 * untuk mencegah serangan MIME Spoofing dan unggahan script berbahaya (PHP/HTML/EXE).
 * @param {Buffer} buf
 * @returns {string|null}
 */
export function detectMagicBytesMime(buf) {
  if (!Buffer.isBuffer(buf) || buf.length < 12) {
    return null;
  }
  // PDF: %PDF- (25 50 44 46 2D)
  if (buf[0] === 0x25 && buf[1] === 0x50 && buf[2] === 0x44 && buf[3] === 0x46 && buf[4] === 0x2D) {
    return 'application/pdf';
  }
  // PNG: 89 50 4E 47 0D 0A 1A 0A
  if (
    buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4E && buf[3] === 0x47 &&
    buf[4] === 0x0D && buf[5] === 0x0A && buf[6] === 0x1A && buf[7] === 0x0A
  ) {
    return 'image/png';
  }
  // JPEG: FF D8 FF
  if (buf[0] === 0xFF && buf[1] === 0xD8 && buf[2] === 0xFF) {
    return 'image/jpeg';
  }
  // WEBP: RIFF....WEBP
  if (
    buf[0] === 0x52 && buf[1] === 0x49 && buf[2] === 0x46 && buf[3] === 0x46 &&
    buf[8] === 0x57 && buf[9] === 0x45 && buf[10] === 0x42 && buf[11] === 0x50
  ) {
    return 'image/webp';
  }
  return null;
}

const EXT_TO_EXPECTED_MIME = {
  '.pdf': 'application/pdf',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp'
};

/**
 * Validates and saves an uploaded file to storage.
 * @param {File} file - Web File object
 * @param {string} folder - Subdirectory name (e.g., 'documents', 'payments', 'uploads')
 * @param {string[]|null} customAllowedExts - Optional custom extension list (e.g., ['pdf', 'png'])
 * @returns {Promise<string>} Public relative URL of the saved file
 */
export async function saveFile(file, folder = 'uploads', customAllowedExts = null) {
  if (!file || typeof file.arrayBuffer !== 'function') {
    throw Object.assign(new Error('File tidak valid.'), { status: 400 });
  }

  // 1. File Size Validation
  if (file.size > MAX_FILE_SIZE_BYTES) {
    throw Object.assign(new Error('Ukuran file melebihi batas maksimal 5 MB.'), { status: 413 });
  }

  // 2. Extension Validation
  const rawExt = path.extname(file.name || '').toLowerCase();
  if (!rawExt || !ALLOWED_EXTENSIONS.has(rawExt)) {
    throw Object.assign(new Error(`Tipe file "${rawExt}" tidak diizinkan. Hanya menerima PDF, JPG, PNG, dan WEBP.`), { status: 400 });
  }

  if (customAllowedExts && customAllowedExts.length > 0) {
    const normalizedCustom = customAllowedExts.map(e => (e.startsWith('.') ? e.toLowerCase() : `.${e.toLowerCase()}`));
    if (!normalizedCustom.includes(rawExt)) {
      throw Object.assign(new Error(`Tipe file harus salah satu dari: ${normalizedCustom.join(', ')}`), { status: 400 });
    }
  }

  // 3. MIME Type & Binary Magic Bytes Validation (DP-06 & APP-06)
  if (file.type && !ALLOWED_MIME_TYPES.has(file.type.toLowerCase())) {
    throw Object.assign(new Error(`MIME type "${file.type}" tidak valid atau tidak didukung.`), { status: 400 });
  }

  const buffer = Buffer.from(await file.arrayBuffer());
  const detectedMime = detectMagicBytesMime(buffer);
  const expectedMime = EXT_TO_EXPECTED_MIME[rawExt];
  if (!detectedMime || !ALLOWED_MIME_TYPES.has(detectedMime) || (expectedMime && detectedMime !== expectedMime)) {
    throw Object.assign(
      new Error('Validasi Magic Bytes gagal (DP-06/APP-06): Isi biner file tidak sesuai dengan format PDF/JPG/PNG/WEBP yang diizinkan (indikasi MIME Spoofing).'),
      { status: 400 }
    );
  }

  // Cegah Path Traversal pada nama folder tujuan
  const safeFolder = path.basename(String(folder || 'uploads')).replace(/[^a-zA-Z0-9_-]/g, '') || 'uploads';
  const fileName = `${crypto.randomUUID()}${rawExt}`;

  // 4. Primary storage: backend/public/<folder> (served directly by Next.js & Nginx)
  const baseUploadDir = process.env.UPLOAD_DIR || path.join(process.cwd(), 'public');
  const targetDir = path.join(baseUploadDir, safeFolder);
  await fs.mkdir(targetDir, { recursive: true });
  const targetPath = path.join(targetDir, fileName);
  await fs.writeFile(targetPath, buffer);

  // 5. Fallback storage for local monorepo Vite dev environment if root/public exists
  try {
    const monorepoPublic = path.join(process.cwd(), '..', 'public', safeFolder);
    await fs.mkdir(monorepoPublic, { recursive: true });
    await fs.writeFile(path.join(monorepoPublic, fileName), buffer);
  } catch {
    // Ignore error if running in standalone container where ../public is not used
  }

  return `/${safeFolder}/${fileName}`;
}
