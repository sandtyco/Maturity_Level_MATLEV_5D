// Warna standar untuk chart (bisa disesuaikan)
const chartColors = [
	'#A73B00', // Traditional
	'#CFC800', // Enhance
	'#14A700', // Mobile
	'#00A795', // Ubiquitous
	'#006AA7'  // Smart
];
// Border colors will be the same as fill colors for no contrast
const chartBorderColors = chartColors; // Menggunakan warna yang sama untuk border dan fill

// Definisi dimensi TEMUS secara global
const dimensionLabels = ['Traditional', 'Enhance', 'Mobile', 'Ubiquitous', 'Smart'];

// Data dari PHP, pastikan di-encode dengan benar
const rpsValues = <?php echo json_encode(array_values($rps_dimension_data)); ?>;
const dosenValues = <?php echo json_encode(array_values($dosen_dimension_data)); ?>;
const mahasiswaValues = <?php echo json_encode(array_values($mahasiswa_dimension_data)); ?>;

// Fungsi untuk mendapatkan warna berdasarkan rata-rata skor TEMUS
// Menggunakan warna yang sama seperti yang ditentukan
function getColorForAverageTemusScore(score) {
	if (score < 2.0) {
		return '#A73B00'; // Traditional
	} else if (score >= 2.0 && score < 3.0) {
		return '#CFC800'; // Enhance
	} else if (score >= 3.0 && score < 4.0) {
		return '#14A700'; // Mobile
	} else if (score >= 4.0 && score <= 4.5) {
		return '#00A795'; // Ubiquitous
	} else if (score > 4.5) {
		return '#006AA7'; // Smart
	}
	return '#808080'; // Default grey if score is out of range
}

// Fungsi untuk membuat pie chart
function createPieChart(ctxId, title, dataValues) {
	const ctx = document.getElementById(ctxId).getContext('2d');
	new Chart(ctx, {
		type: 'pie',
		data: {
			labels: dimensionLabels, // Menggunakan variabel global dimensionLabels
			datasets: [{
				label: title,
				data: dataValues,
				backgroundColor: chartColors, // Menggunakan warna yang sudah ditentukan
				borderColor: chartBorderColors, // Menggunakan warna border yang sudah ditentukan
				borderWidth: 1
			}]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: {
				legend: {
					position: 'right',
					labels: {
						// Filter ini akan memastikan semua item legenda ditampilkan,
						// bahkan jika nilai data-nya 0.
						filter: function(legendItem, chartData) {
							return true; // Selalu kembalikan true untuk menampilkan semua item
						}
					}
				},
				title: {
					display: true,
					text: title
				},
				tooltip: { // Menambahkan konfigurasi tooltip agar lebih informatif
					callbacks: {
						label: function(context) {
							let label = context.label || '';
							if (label) {
								label += ': ';
							}
							if (context.parsed !== null) {
								const total = context.dataset.data.reduce((acc, current) => acc + current, 0);
								// Hindari pembagian dengan nol jika totalnya 0
								const percentage = total === 0 ? 0 : (context.parsed / total * 100).toFixed(2);
								label += context.parsed + ' (' + percentage + '%)';
							}
							return label;
						}
					}
				}
			}
		}
	});
}

// Inisialisasi Chart.js setelah DOM dimuat
document.addEventListener('DOMContentLoaded', function() {
	createPieChart('rpsDimensionChart', 'RPS Mata Kuliah', rpsValues);
	createPieChart('dosenDimensionChart', 'Gaya Dosen', dosenValues);
	createPieChart('mahasiswaDimensionChart', 'Pengalaman Mahasiswa', mahasiswaValues);
});

// Global chart instances for the new interactive charts
var averageTemusChartInstance;
var globalTemusDistributionChartInstance;

// New interactive charts logic
$('#institutionSelect').change(function() {
	var institutionId = $(this).val();
	var institutionName = $(this).find('option:selected').text();
	
	if (institutionId) {
		$('#selectedInstitutionName').text(institutionName);
		$('#statisticsDisplay').show();
		$('#noDataMessage').hide(); // Hide no data message initially
		$('#chartDescription').text('Memuat data statistik untuk ' + institutionName + '...');

		// Fetch data via AJAX
		$.ajax({
			url: '../api/get_institution_stats.php',
			type: 'GET',
			data: { institution_id: institutionId },
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					if (response.has_data) {
						$('#chartDescription').text('Statistik digitalisasi pembelajaran di ' + institutionName + '.');
						$('#noDataMessage').hide();

						// Generate colors for the bars based on scores
						const barBackgroundColors = response.avg_temus_data.data.map(score => getColorForAverageTemusScore(score));
						// Border colors will now be the same as background colors for no contrast
						const barBorderColors = barBackgroundColors;

						// Update Average TEMUS Chart (Horizontal Bar Chart)
						if (averageTemusChartInstance) averageTemusChartInstance.destroy();
						const avgTemusCtx = document.getElementById('averageTemusChart').getContext('2d');
						averageTemusChartInstance = new Chart(avgTemusCtx, {
							type: 'bar',
							data: {
								labels: response.avg_temus_data.labels,
								datasets: [{
									label: 'Rata-rata TEMUS',
									data: response.avg_temus_data.data,
									backgroundColor: barBackgroundColors, // <--- Warna dinamis
									borderColor: barBorderColors,       // <--- Border dinamis (sekarang sama dengan fill)
									borderWidth: 1
								}]
							},
							options: {
								indexAxis: 'y', // <--- PENTING: Mengubah menjadi horizontal bar chart
								responsive: true,
								maintainAspectRatio: false,
								scales: {
									x: { // x-axis sekarang adalah sumbu nilai
										beginAtZero: true,
										max: 5, // Assuming TEMUS score is 1-5
										title: {
											display: true,
											text: 'Skor Rata-rata'
										}
									},
									y: { // y-axis sekarang adalah sumbu kategori (program studi)
										// Tidak perlu beginAtZero atau max di sini
									}
								},
								plugins: {
									legend: {
										display: false // Legenda tidak perlu karena warna sudah diinterpretasikan
									},
									tooltip: {
										callbacks: {
											label: function(context) {
												let label = context.dataset.label || '';
												if (label) {
													label += ': ';
												}
												if (context.parsed.x !== null) {
													label += context.parsed.x.toFixed(2);
												}
												// Optional: Add interpretation text to tooltip
												let interpretation = '';
												const score = context.parsed.x;
												if (score < 2.0) {
													interpretation = ' (Traditional)';
												} else if (score >= 2.0 && score < 3.0) {
													interpretation = ' (Enhance)';
												} else if (score >= 3.0 && score < 4.0) {
													interpretation = ' (Mobile)';
												} else if (score >= 4.0 && score <= 4.5) {
													interpretation = ' (Ubiquitous)';
												} else if (score > 4.5) {
													interpretation = ' (Smart)';
												}
												return label + interpretation;
											}
										}
									}
								}
							}
						});

						// Update Global TEMUS Distribution Chart (Pie Chart)
						if (globalTemusDistributionChartInstance) globalTemusDistributionChartInstance.destroy();
						const globalTemusCtx = document.getElementById('globalTemusDistributionChart').getContext('2d');
						globalTemusDistributionChartInstance = new Chart(globalTemusCtx, {
							type: 'pie',
							data: {
								labels: response.global_temus_distribution_data.labels,
								datasets: [{
									label: 'Distribusi Dimensi TEMUS',
									data: response.global_temus_distribution_data.data,
									backgroundColor: chartColors, // Re-use existing TEMUS colors
									borderColor: chartBorderColors, // Re-use existing TEMUS colors (sekarang sama dengan fill)
									borderWidth: 1
								}]
							},
							options: {
								responsive: true,
								maintainAspectRatio: false,
								plugins: {
									legend: {
										position: 'top',
										labels: {
											// Filter ini juga memastikan semua item legenda ditampilkan
											filter: function(legendItem, chartData) {
												return true; // Selalu kembalikan true
											}
										}
									},
									tooltip: {
										callbacks: {
											label: function(context) {
												let label = context.label || '';
												if (label) {
													label += ': ';
												}
												if (context.parsed !== null) {
													const total = context.dataset.data.reduce((acc, current) => acc + current, 0);
													const percentage = total === 0 ? 0 : (context.parsed / total * 100).toFixed(2);
													label += context.parsed + ' (' + percentage + '%)';
												}
												return label;
											}
										}
									},
									title: {
										display: true,
										text: 'Distribusi Global Dimensi TEMUS'
									}
								}
							}
						});

					} else {
						// No data for this institution
						$('#statisticsDisplay').hide();
						$('#noDataMessage').show().text('Tidak ada data evaluasi yang cukup untuk institusi ' + institutionName + '.');
					}
				} else {
					// API reported an error
					$('#statisticsDisplay').hide();
					$('#noDataMessage').show().text('Gagal memuat data: ' + response.message);
					console.error("API Error: " + response.message);
				}
			},
			error: function(xhr, status, error) {
				$('#statisticsDisplay').hide();
				$('#noDataMessage').show().text('Terjadi kesalahan saat mengambil data. Silakan coba lagi.');
				console.error("AJAX Error: " + status + ": " + error);
			}
		});
	} else {
		$('#statisticsDisplay').hide();
		$('#noDataMessage').hide();
		$('#chartDescription').text('Silakan pilih institusi untuk melihat statistik...');
	}
});