import { ChartJSNodeCanvas } from 'chartjs-node-canvas';
import fs from 'fs';

const width = 1200;
const height = 800;

const input = JSON.parse(fs.readFileSync(0, 'utf-8'));

const chartJSNodeCanvas = new ChartJSNodeCanvas({
  width,
  height,
  backgroundColour: 'white'
});

const configuration = {
  type: input.type,
  data: {
    labels: input.labels,
    datasets: [
      {
        label: input.datasetLabel,
        data: input.values
      }
    ]
  },
  options: {
    responsive: false,
    plugins: {
      legend: { display: true }
    },
    scales: {
      y: {
        min: -4,
        max: 4
      }
    }
  }
};

const buffer = await chartJSNodeCanvas.renderToBuffer(configuration);

process.stdout.write(buffer);
