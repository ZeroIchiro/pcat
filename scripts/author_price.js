(() => {
  const checkbox = document.querySelector('#use-author-margin');
  const discountInput = document.querySelector('#discount-percent');
  if (!checkbox || !discountInput) return;

  const updateDiscountInput = () => {
    discountInput.disabled = checkbox.checked;
  };

  checkbox.addEventListener('change', updateDiscountInput);
  updateDiscountInput();
})();