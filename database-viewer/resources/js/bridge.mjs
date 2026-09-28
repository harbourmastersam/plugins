const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const finite = value => typeof value === 'number' && Number.isFinite(value);
const scalar = value => value === null || typeof value === 'string' || typeof value === 'boolean' || finite(value);
const byteLength = value => new TextEncoder().encode(value).byteLength;
const AI_MAX_MESSAGES = 12;
const AI_MAX_CONTENT_BYTES = 24 * 1024;
const AI_MAX_RESPONSE_BYTES = 16 * 1024;

function denseArray(value, validate) {
    if (!Array.isArray(value) || Object.keys(value).length !== value.length) return false;
    for (let index = 0; index < value.length; index++) {
        if (!Object.hasOwn(value, index) || !validate(value[index])) return false;
    }
    return true;
}

function exactKeys(value, expected) {
    const keys = Object.keys(value).sort();
    return keys.length === expected.length && keys.every((key, index) => key === [...expected].sort()[index]);
}

function copyResult(data) {
    if (!record(data) || !record(data.stat)) return null;
    if (!denseArray(data.headers, header => record(header)
        && typeof header.name === 'string'
        && typeof header.displayName === 'string'
        && (header.originalType === null || typeof header.originalType === 'string')
        && (header.type === undefined || [1, 2, 3, 4].includes(header.type)))) return null;
    if (!denseArray(data.rows, row => record(row) && Object.values(row).every(scalar))) return null;
    if (!finite(data.stat.rowsAffected)
        || ![data.stat.rowsRead, data.stat.rowsWritten].every(value => value === null || finite(value))
        || !(data.stat.queryDurationMs === null || (finite(data.stat.queryDurationMs) && data.stat.queryDurationMs >= 0))
        || !(data.lastInsertRowid === undefined || finite(data.lastInsertRowid))) return null;

    const result = {
        headers: data.headers.map(header => {
            const copy = {
                name: header.name,
                displayName: header.displayName,
                originalType: header.originalType,
            };
            if (header.type !== undefined) copy.type = header.type;
            return copy;
        }),
        rows: data.rows.map(row => Object.fromEntries(Object.entries(row))),
        stat: {
            rowsAffected: data.stat.rowsAffected,
            rowsRead: data.stat.rowsRead,
            rowsWritten: data.stat.rowsWritten,
            queryDurationMs: data.stat.queryDurationMs,
        },
    };
    if (data.lastInsertRowid !== undefined) result.lastInsertRowid = data.lastInsertRowid;
    return result;
}

function validMessages(messages) {
    if (!denseArray(messages, message => record(message)
        && exactKeys(message, ['role', 'content'])
        && ['system', 'user', 'assistant'].includes(message.role)
        && typeof message.content === 'string')) return false;
    if (messages.length < 1 || messages.length > AI_MAX_MESSAGES) return false;
    return messages.reduce((total, message) => total + byteLength(message.content), 0) <= AI_MAX_CONTENT_BYTES;
}

function copyAiResult(data) {
    if (!record(data) || !exactKeys(data, ['response']) || typeof data.response !== 'string') return null;
    if (byteLength(data.response) > AI_MAX_RESPONSE_BYTES) return null;
    return { response: data.response };
}

function validRequest(message) {
    if (!record(message) || !Number.isSafeInteger(message.id) || !/^[a-f0-9]{32}$/.test(message.document)) return false;
    if (message.type === 'query') {
        return exactKeys(message, ['type', 'id', 'channel', 'document', 'statement'])
            && typeof message.statement === 'string'
            && byteLength(message.statement) <= 2048;
    }
    if (message.type === 'transaction') {
        return exactKeys(message, ['type', 'id', 'channel', 'document', 'statements'])
            && denseArray(message.statements, statement => typeof statement === 'string' && byteLength(statement) <= 2048)
            && message.statements.length === 6;
    }
    if (message.type === 'ai') {
        return exactKeys(message, ['type', 'id', 'channel', 'document', 'messages'])
            && validMessages(message.messages);
    }
    return false;
}

export function createBridge({ iframe, origin, channel, broker }) {
    let active = true;
    let generation = 0;
    const pending = new Set();
    return {
        dispose() { active = false; generation++; pending.clear(); },
        reset() { generation++; pending.clear(); },
        async handle(event) {
            if (!active || event.origin !== origin || event.source !== iframe.contentWindow) return;
            const message = event.data;
            if (!validRequest(message) || message.channel !== channel) return;
            const pendingKey = `${message.document}:${message.id}`;
            if (pending.has(pendingKey)) return;

            const target = iframe.contentWindow;
            const current = generation;
            const identity = { type: message.type, id: message.id, channel, document: message.document };
            const reply = payload => {
                if (active && generation === current && iframe.contentWindow === target) {
                    target.postMessage({ ...identity, ...payload }, origin);
                }
            };
            pending.add(pendingKey);
            try {
                const payload = message.type === 'query'
                    ? { type: 'query', statement: message.statement, channel }
                    : message.type === 'transaction'
                        ? { type: 'transaction', statements: [...message.statements], channel }
                        : { type: 'ai', messages: message.messages.map(item => ({ role: item.role, content: item.content })), channel };
                const backendData = await broker(payload);
                if (message.type === 'query') {
                    const data = copyResult(backendData);
                    if (data === null) throw new Error('Invalid result');
                    reply({ data });
                } else if (message.type === 'transaction') {
                    if (!denseArray(backendData, record) || backendData.length !== message.statements.length) {
                        throw new Error('Invalid transaction result');
                    }
                    const data = backendData.map(copyResult);
                    if (data.some(item => item === null)) throw new Error('Invalid transaction result');
                    reply({ data });
                } else {
                    const data = copyAiResult(backendData);
                    if (data === null) throw new Error('Invalid AI result');
                    reply({ data });
                }
            } catch {
                reply({ error: message.type === 'ai' ? 'AI request failed.' : 'Database query failed.' });
            } finally {
                if (generation === current) pending.delete(pendingKey);
            }
        },
    };
}
