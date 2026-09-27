const allowedQuery = /^[ \t\r\n]*SELECT[ \t\r\n]+1[ \t\r\n]*;?[ \t\r\n]*$/i;
const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);

export function withProbeMode(value) {
    const url = new URL(value);
    url.searchParams.set('mode', 'probe');
    return url.toString();
}

function validResult(data) {
    return record(data) && Array.isArray(data.headers) && data.headers.length === 1
        && record(data.headers[0]) && data.headers[0].name === '1' && data.headers[0].displayName === '1'
        && data.headers[0].originalType === 'INT' && data.headers[0].type === 2
        && Array.isArray(data.rows) && data.rows.length === 1 && record(data.rows[0]) && data.rows[0]['1'] === 1
        && record(data.stat) && data.stat.rowsAffected === 0 && data.stat.rowsRead === 1
        && data.stat.rowsWritten === null && Number.isFinite(data.stat.queryDurationMs) && data.stat.queryDurationMs >= 0;
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
            if (!record(message) || message.channel !== channel || !Number.isSafeInteger(message.id)) return;
            if (message.type !== 'query' && message.type !== 'transaction') return;
            if (message.type === 'query' && typeof message.statement !== 'string') return;
            if (message.type === 'transaction' && (!Array.isArray(message.statements)
                || !message.statements.every(statement => typeof statement === 'string'))) return;
            if (pending.has(message.id)) return;
            const target = iframe.contentWindow;
            const current = generation;
            const identity = { type: message.type, id: message.id, channel };
            const reply = payload => {
                if (active && generation === current && iframe.contentWindow === target) target.postMessage({ ...identity, ...payload }, origin);
            };
            if (message.type === 'transaction') { reply({ error: 'Transactions are not supported in MVP mode.' }); return; }
            if (message.statement.length > 128 || !allowedQuery.test(message.statement)) { reply({ error: 'Query not permitted in MVP mode.' }); return; }
            pending.add(message.id);
            try {
                const data = await broker({ statement: message.statement, channel });
                if (!validResult(data)) throw new Error('Invalid result');
                // Copy only the contract fields; never forward arbitrary backend properties.
                reply({ data: { headers: [{ name: '1', displayName: '1', originalType: 'INT', type: 2 }], rows: [{ '1': 1 }], stat: { rowsAffected: 0, rowsRead: 1, rowsWritten: null, queryDurationMs: data.stat.queryDurationMs } } });
            } catch {
                reply({ error: 'Database query failed.' });
            } finally {
                if (generation === current) pending.delete(message.id);
            }
        },
    };
}
