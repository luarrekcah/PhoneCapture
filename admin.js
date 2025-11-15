jQuery(document).ready(function($){
    if (typeof pcmChartLabels !== 'undefined' && typeof pcmChartData !== 'undefined') {
        var ctx = document.getElementById('pcmChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: pcmChartLabels,
                datasets: [{
                    label: 'Visualizações por dia',
                    data: pcmChartData,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } }
            }
        });
    }
});
