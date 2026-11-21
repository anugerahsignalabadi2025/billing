(function () {
    function cleanLocalizedAmount(value) {
        const parts = value.replace(/[^\d,.]/g, '').replace(/\./g, '').split(',');
        const integer = parts.shift() || '';
        const decimal = parts.join('').slice(0, 2);
        return { integer, decimal, hasDecimal: value.includes(',') };
    }

    function formatAmount(value) {
        const amount = cleanLocalizedAmount(value);
        const groupedInteger = amount.integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return groupedInteger + (amount.hasDecimal ? ',' + amount.decimal : '');
    }

    function formatInitialAmount(value) {
        const [integer = '', decimal = ''] = value.split('.');
        const groupedInteger = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return groupedInteger + (decimal ? ',' + decimal.slice(0, 2) : '');
    }

    function normalizeAmount(value) {
        const amount = cleanLocalizedAmount(value);
        return amount.integer + (amount.hasDecimal && amount.decimal ? '.' + amount.decimal : '');
    }

    document.querySelectorAll('[data-money-input]').forEach(function (input) {
        input.value = formatInitialAmount(input.value);

        input.addEventListener('input', function () {
            const charactersBeforeCaret = input.value.slice(0, input.selectionStart).replace(/\./g, '').length;
            input.value = formatAmount(input.value);
            let caret = 0;
            let countedCharacters = 0;
            while (caret < input.value.length && countedCharacters < charactersBeforeCaret) {
                if (input.value[caret] !== '.') countedCharacters++;
                caret++;
            }
            input.setSelectionRange(caret, caret);
        });

        input.form.addEventListener('submit', function () {
            input.value = normalizeAmount(input.value);
        });
    });

    document.querySelectorAll('input[type="date"].date-picker-input').forEach(function (input) {
        input.addEventListener('click', function () {
            if (typeof input.showPicker === 'function') {
                input.showPicker();
            }
        });
    });
})();
