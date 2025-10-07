/**
 * MyComponent example component
 */
import Hi from '@/components/hi';
const MyComponent = ({
  greeting = 'Hello world!',
  cta = 'Click me!',
  ctaRef = 'https://example.com',
  content,
}) => {
  return (
    <div className="my-component-component">
      <Hi/>
      <h2 className="greeting">{greeting}</h2>
      <div className="content">{content}</div>
      <button type="button" className="cta">
        <a href={ctaRef}>{cta}</a>
      </button>
    </div>
  );
};

export default MyComponent;
