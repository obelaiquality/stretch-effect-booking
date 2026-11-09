import Icon from './Icon';
import './components.css';

interface Props { message: string; onRetry: () => void }

/** Error state: one sentence in red-text and a retry button. */
export default function ErrorState({ message, onRetry }: Props) {
  return (
    <div className="tse-error" role="alert">
      <p className="error-text tse-error-text"><Icon name="alert" size={18} /> {message}</p>
      <button type="button" className="btn btn-ghost" onClick={onRetry}>Try again</button>
    </div>
  );
}
