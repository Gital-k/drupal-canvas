import { usePageContext } from 'drupal-canvas';

const PageTitle = () => {
  const page = usePageContext();
  if (!page?.pageTitle) {
    return null;
  }
  return <h1>{page.pageTitle}</h1>;
};

export default PageTitle;
