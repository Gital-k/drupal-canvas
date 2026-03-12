const { jQuery } = window as any;

let ajaxCount = 0;

jQuery(document).on('ajaxStart', () => {
  ajaxCount++;
});
jQuery(document).on('ajaxStop', () => {
  ajaxCount = 0;
});

export const isAjaxing = () => ajaxCount > 0;
