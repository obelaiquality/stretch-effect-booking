import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import './admin.css';
import { formatDay, formatDayTime } from '../../lib/format';
import { AdminFrame, AdminHead, Balance, CardSkel, Empty, ErrorState, useLoad } from './ui';

export default function AdminClients() {
  const [q, setQ] = useState('');
  const [debounced, setDebounced] = useState('');
  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(q.trim()), 200);
    return () => window.clearTimeout(t);
  }, [q]);
  const { data, error, reload } = useLoad(() => api.adminClients(debounced || undefined), [debounced]);

  return (
    <AdminFrame>
      <AdminHead title="Clients" />
      <div className="field" style={{ maxWidth: 480, marginBottom: 'var(--s5)' }}>
        <label htmlFor="cq">Search by name or email</label>
        <input id="cq" className="input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Start typing" autoComplete="off" />
      </div>
      {error && <ErrorState message={error} onRetry={reload} />}
      {!data && !error && (
        <div className="card">
          <CardSkel lines={6} />
        </div>
      )}
      {data && data.length === 0 && (
        debounced ? (
          <Empty actionLabel="Clear the search" onAction={() => setQ('')}>{`No clients match "${debounced}".`}</Empty>
        ) : (
          <Empty to="/admin" actionLabel="Back to today">No clients yet. They appear here when they sign up.</Empty>
        )
      )}
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
                        <Balance n={c.balance} />
                      </td>
                      <td className="num">{c.upcoming}</td>
                      <td className="num">{c.lastVisit ? formatDay(c.lastVisit) : 'None yet'}</td>
                      <td className="num">{c.pendingPacks > 0 ? <span className="bal bal-1">{c.pendingPacks} waiting</span> : '0'}</td>
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
                    <Balance n={c.balance} />
                  </div>
                  <div className="muted small">{c.user.email}</div>
                  <div className="small num silver">
                    {c.upcoming} upcoming · {c.lastVisit ? `last ${formatDayTime(c.lastVisit)}` : 'no visit yet'}
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
