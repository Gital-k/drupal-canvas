import introVideo from '@/assets/intro.mp4';

import heroImage from './hero.webp';

export default function Example() {
  return (
    <article>
      <img alt="" src={heroImage} />
      <video src={introVideo} />
    </article>
  );
}
