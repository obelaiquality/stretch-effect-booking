import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import './admin.css';
import { AdminFrame, AdminHead, Empty, useLoad } from './ui';
import { fmtDate, fmtWhen } from './format';

export default function AdminClients() {
  const [q, setQ] = useState('');
  const [debounced, setDebounced] = useState('');
  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(q.trim()), 200);
    return () => window.clearTimeout(t);
  }, [q]);
  const { data, error } = useLoad(() => api.adminClients(debounced || undefined), [debounced]);

  return (
    <AdminFrame>
      <AdminHead eyebrow="Owner" title={<>Clients<span className="red">.</span></>} />
      <div className="field" style={{ maxWidth: 480, marginBottom: 'var(--s5)' }}>
        <label htmlFor="cq">Search by name or email</label>
        <input id="cq" className="input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Start typing" autoComplete="off" />
      </div>
      {error && <p className="error-text">{error}</p>}
      {!data && !error && <p className="muted" aria-busy="true">Loading...</p>}
      {data && data.length === 0 && <Empty>No clients match "{debounced}".</Empty>}
      {data && data.length > 0 && (
        <>
          <div className="card only-wide" style={{ padding: 0 }}>
            <div className="table-scroll">
              <table className="table">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th>Email</th>
                    <th>Sessions left</th>
                    <th>Upcoming</th>
                    <th>Last visit</th>
                    <th>Pending packs</th>
                  </tr>
                </thead>
                <tbody>
                  {data.map((c) => (
                    <tr key={c.user.id}>
                      <td>
                        <Link to={`/admin/clients/${c.user.id}`}>
                          <strong>{c.user.name}</strong>
                        </Link>
                      </td>
                      <td className="muted">{c.user.email}</td>
                      <td className="num">
                        <span className={'badge ' + (c.balance === 0 ? 'badge-red' : c.balance === 1 ? 'badge-warn' : '')}>{c.balance}</span>
                      </td>
                      <td className="num">{c.upcoming}</td>
                      <td className="num">{c.lastVisit ? fmtDate(c.lastVisit) : 'None yet'}</td>
                      <td className="num">{c.pendingPacks > 0 ? <span className="badge badge-warn">{c.pendingPacks}</span> : '0'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
          <ul className="a-list only-narrow">
            {data.map((c) => (
              <li key={c.user.id} className="card card-tight client-card">
                <Link to={`/admin/clients/${c.user.id}`} className="client-card-link">
                  <div className="row">
                    <strong>{c.user.name}</strong>
                    <span className="spacer" />
                    <span className={'badge ' + (c.balance === 0 ? 'badge-red' : c.balance === 1 ? 'badge-warn' : '')}>
                      {c.balance} left
                    </span>
                  </div>
                  <div className="muted small">{c.user.email}</div>
                  <div className="small num silver">
                    {c.upcoming} upcoming · {c.lastVisit ? `last ${fmtWhen(c.lastVisit)}` : 'no visit yet'}
                    {c.pendingPacks > 0 ? ` · ${c.pendingPacks} pending` : ''}
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        </>
      )}
    </AdminFrame>
  );
}
