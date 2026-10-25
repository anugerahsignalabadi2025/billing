$(document).ready(function() {
  var table = $('#dataTable');
  var options = {
    language: {
      emptyTable: 'Belum ada data',
      info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
      infoEmpty: 'Menampilkan 0 sampai 0 dari 0 data',
      infoFiltered: '(disaring dari _MAX_ total data)',
      lengthMenu: 'Tampilkan _MENU_ data',
      loadingRecords: 'Memuat...',
      processing: 'Memproses...',
      search: 'Cari data:',
      zeroRecords: 'Data yang dicari tidak ditemukan',
      paginate: {
        first: 'Pertama',
        last: 'Terakhir',
        next: 'Berikutnya',
        previous: 'Sebelumnya'
      }
    }
  };

  if (table.data('sequential-number')) {
    options.order = [];
    options.drawCallback = function() {
      var api = this.api();
      var start = api.page.info().start;
      api.column(0, { page: 'current' }).nodes().each(function(cell, index) {
        cell.textContent = start + index;
      });
    };
  }

  table.DataTable(options);
});
