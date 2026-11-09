import { useState } from 'react';
import Icon from './Icon';
import './components.css';

interface Props {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  autoComplete: string;
  minLength?: number;
  describedBy?: string;
  hint?: string;
}

/** Password input with a 44px show/hide toggle. */
export default function PasswordField({ id, label, value, onChange, autoComplete, minLength, describedBy, hint }: Props) {
  const [show, setShow] = useState(false);
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <div className="tse-pw">
        <input
          id={id} className="input" type={show ? 'text' : 'password'} autoComplete={autoComplete}
          minLength={minLength} value={value} onChange={(e) => onChange(e.target.value)} aria-describedby={describedBy} required
        />
        <button type="button" className="tse-pw-toggle" aria-pressed={show} aria-label={show ? 'Hide password' : 'Show password'} onClick={() => setShow((s) => !s)}>
          <Icon name={show ? 'eye-off' : 'eye'} size={20} />
        </button>
      </div>
      {hint && <span id={describedBy} className="hint">{hint}</span>}
    </div>
  );
}
