// API base is now the root /api path since the app is served from the domain root
const API_BASE = '/api';

export async function post(endpoint, data) {
    const response = await fetch(`${API_BASE}${endpoint}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });
    // read raw text to safely parse JSON or return fallback
    const text = await response.text();
    if (!response.ok) {
        try {
            const errData = text ? JSON.parse(text) : {};
            throw new Error(errData.message || errData.error || 'API Error');
        } catch (e) {
            // If JSON parsing fails, throw raw text as message
            throw new Error(text || 'API Error');
        }
    }
    if (!text) return {};
    try {
        return JSON.parse(text);
    } catch {
        return { data: text };
    }
}

export async function get(endpoint) {
    const response = await fetch(`${API_BASE}${endpoint}`);
    const text = await response.text();
    if (!response.ok) {
        try {
            const errData = text ? JSON.parse(text) : {};
            throw new Error(errData.message || errData.error || 'API Error');
        } catch (e) {
            throw new Error(text || 'API Error');
        }
    }
    if (!text) return {};
    try {
        return JSON.parse(text);
    } catch {
        return { data: text };
    }
}
