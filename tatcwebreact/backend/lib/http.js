const DEFAULT_ALLOWED_ORIGINS = [
  'http://localhost:5173',
  'http://127.0.0.1:5173',
  'http://localhost:8080',
  'http://127.0.0.1:8080',
];

export const SECURITY_HEADERS = {
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'SAMEORIGIN',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'X-Permitted-Cross-Domain-Policies': 'none',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
};

export function getAllowedOrigins() {
  const raw = [process.env.CORS_ORIGIN, process.env.FRONTEND_URL]
    .filter(Boolean)
    .join(',');
  const configured = raw
    .split(',')
    .map(o => o.trim())
    .filter(o => o && o !== '*');
  return configured.length > 0
    ? Array.from(new Set([...configured, ...DEFAULT_ALLOWED_ORIGINS]))
    : DEFAULT_ALLOWED_ORIGINS;
}

export function resolveAllowedOrigin(requestOrigin = null) {
  const allowlist = getAllowedOrigins();
  if (requestOrigin && allowlist.includes(requestOrigin)) {
    return requestOrigin;
  }
  return allowlist[0];
}

export function buildCorsHeaders(requestOrigin = null) {
  const origin = resolveAllowedOrigin(requestOrigin);
  return {
    'Access-Control-Allow-Origin': origin,
    'Access-Control-Allow-Credentials': 'true',
    'Vary': 'Origin',
  };
}

export function json(data, status = 200, extra = {}) {
  const corsHeaders = buildCorsHeaders();
  return Response.json(data, {
    status,
    headers: {
      'Cache-Control': 'no-store, no-cache, must-revalidate, proxy-revalidate',
      'Pragma': 'no-cache',
      ...SECURITY_HEADERS,
      ...corsHeaders,
      ...extra,
    },
  });
}
export function ok(data, message = null) {
  return json({ success: true, ...(message ? { message } : {}), data });
}
export function fail(message, status = 400, details = undefined) {
  return json({ success: false, message, ...(details ? { details } : {}) }, status);
}
export async function body(request) {
  const type = request.headers.get('content-type') || '';
  if (type.includes('application/json')) return await request.json();
  if (type.includes('multipart/form-data')) return Object.fromEntries(await request.formData());
  return {};
}

