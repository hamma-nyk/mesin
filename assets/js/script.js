async function kirimNotif(agenda_id, nik, nama, no_wa) {
    const btn = document.getElementById(`btn-${nik}`);
    const statusCell = document.getElementById(`status-${nik}`);
    
    // DETEKSI: Apakah sedang mode "Kirim Semua"? 
    // Kita cek apakah tombol "Kirim ke Semua" sedang dalam keadaan disabled
    const btnKirimSemua = document.getElementById('btn-kirim-semua');
    const isMassSending = btnKirimSemua && btnKirimSemua.disabled === true;
    
    // Matikan tombol saat diklik
    btn.disabled = true;
    
    // Ganti teks dan ikon menjadi Spinner berputar
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Mengirim...';

    try {
        const response = await fetch('api/proses_kirim', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agenda_id, nik, nama, no_wa })
        });

        const result = await response.json();

        if (result.success) {
            // Update Status Badge ke warna Hijau Bootstrap
            statusCell.innerText = "TERKIRIM";
            statusCell.className = "badge badge-success";
            
            // Update Tombol ke warna abu-abu / default AdminLTE
            btn.innerHTML = '<i class="fas fa-check mr-1"></i> Terkirim';
            btn.className = "btn btn-default btn-sm"; 
            
            // Kunci tombol selamanya karena sudah sukses terkirim
            btn.disabled = true; 
            
        } else {
            // Update Status Badge ke warna Merah Bootstrap
            statusCell.innerText = "GAGAL";
            statusCell.className = "badge badge-danger";
            
            // Ubah jadi warna Merah (Kirim Ulang)
            btn.innerHTML = '<i class="fas fa-redo mr-1"></i> Kirim Ulang';
            btn.className = "btn btn-danger btn-sm btn-kirim-wa"; 
            
            // Hanya hidupkan tombol JIKA TIDAK SEDANG proses massal
            if (!isMassSending) {
                btn.disabled = false;
                alert("Gagal kirim ke " + nama + ": " + result.message);
            } else {
                console.warn("Gagal kirim ke " + nama + ": " + result.message);
            }
        }
    } catch (error) {
        console.error("Error:", error);
        
        // Update Status Badge ke warna Merah
        statusCell.innerText = "GAGAL";
        statusCell.className = "badge badge-danger";
        
        // Ubah tombol jadi Kuning/Merah penanda Error
        btn.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i> Error';
        btn.className = "btn btn-warning btn-sm btn-kirim-wa";
        
        // Hanya hidupkan tombol dan munculkan alert JIKA TIDAK SEDANG proses massal
        if (!isMassSending) {
            btn.disabled = false;
            alert("Terjadi kesalahan sistem saat menghubungi API untuk " + nama);
        }
    }
}