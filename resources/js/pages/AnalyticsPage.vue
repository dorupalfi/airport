<script setup>
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { Bar, Line } from 'vue-chartjs';
import { computed, onMounted, ref, watch } from 'vue';

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, Tooltip, Legend, Filler);

const airports = ref([]);
const dates = ref([]);
const snapshots = ref([]);
const selectedAirportId = ref('');
const selectedDate = ref('');
const error = ref('');
const isLoadingFilters = ref(true);
const isLoadingSnapshots = ref(false);
const isRebuilding = ref(false);
const filtersReady = ref(false);

const latestSnapshot = computed(() => snapshots.value.at(-1) ?? null);
const chartLabels = computed(() => snapshots.value.map((snapshot) => formatTime(snapshot.captured_at)));

const gateChartData = computed(() => ({
    labels: chartLabels.value,
    datasets: [
        { label: 'Free', data: snapshots.value.map((snapshot) => snapshot.free_gates), backgroundColor: '#53ab9d' },
        { label: 'Busy', data: snapshots.value.map((snapshot) => snapshot.busy_gates), backgroundColor: '#315d5a' },
        { label: 'Exception blocked', data: snapshots.value.map((snapshot) => snapshot.exception_blocked_gates), backgroundColor: '#e5a34f' },
        { label: 'Inactive', data: snapshots.value.map((snapshot) => snapshot.inactive_gates), backgroundColor: '#9aa9bc' },
    ],
}));

const flightChartData = computed(() => ({
    labels: chartLabels.value,
    datasets: [
        { label: 'On time', data: snapshots.value.map((snapshot) => snapshot.on_time_flights), backgroundColor: '#53ab9d' },
        { label: 'Delayed', data: snapshots.value.map((snapshot) => snapshot.delayed_flights), backgroundColor: '#d97706' },
        { label: 'Unallocated', data: snapshots.value.map((snapshot) => snapshot.unallocated_flights), backgroundColor: '#c85a54' },
        { label: 'Pending', data: snapshots.value.map((snapshot) => snapshot.pending_flights), backgroundColor: '#9aa9bc' },
    ],
}));

const healthChartData = computed(() => ({
    labels: chartLabels.value,
    datasets: [
        {
            label: 'Active exceptions',
            data: snapshots.value.map((snapshot) => snapshot.active_exceptions),
            borderColor: '#d97706',
            backgroundColor: 'rgb(217 119 6 / 14%)',
            fill: true,
            tension: 0.28,
        },
        {
            label: 'Invalid allocations',
            data: snapshots.value.map((snapshot) => snapshot.invalid_allocations),
            borderColor: '#c85a54',
            backgroundColor: 'rgb(200 90 84 / 10%)',
            fill: true,
            tension: 0.28,
        },
    ],
}));

const stackedBarOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } },
        tooltip: { padding: 10 },
    },
    scales: {
        x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf0f5' } },
    },
};

const lineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } },
        tooltip: { padding: 10 },
    },
    scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf0f5' } },
    },
};

async function loadFilters() {
    isLoadingFilters.value = true;
    error.value = '';

    try {
        const response = await fetch('/api/analytics/filters', {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load analytics filters.');
        }

        const payload = await response.json();
        airports.value = payload.airports;
        dates.value = payload.dates;
        selectedAirportId.value = airports.value[0]?.id ?? '';
        selectedDate.value = dates.value[0] ?? '';
        filtersReady.value = true;
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isLoadingFilters.value = false;
    }
}

async function loadSnapshots() {
    if (!filtersReady.value || !selectedAirportId.value || !selectedDate.value) {
        snapshots.value = [];

        return;
    }

    isLoadingSnapshots.value = true;
    error.value = '';

    try {
        const query = new URLSearchParams({
            airport_id: selectedAirportId.value,
            date: selectedDate.value,
        });
        const response = await fetch(`/api/analytics?${query}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load allocation snapshots.');
        }

        const payload = await response.json();
        snapshots.value = payload.snapshots;
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isLoadingSnapshots.value = false;
    }
}

async function reloadPage() {
    await loadFilters();
    await loadSnapshots();
}

async function rebuildSnapshots() {
    if (!selectedAirportId.value || !selectedDate.value) {
        return;
    }

    isRebuilding.value = true;
    error.value = '';

    try {
        const response = await fetch('/api/analytics/rebuild', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                airport_id: selectedAirportId.value,
                date: selectedDate.value,
            }),
        });

        if (!response.ok) {
            throw new Error('Unable to rebuild allocation analytics.');
        }

        await loadSnapshots();
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isRebuilding.value = false;
    }
}

function formatTime(value) {
    return new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: 'UTC',
    }).format(new Date(value));
}

watch([selectedAirportId, selectedDate], loadSnapshots);

onMounted(async () => {
    await loadFilters();
    await loadSnapshots();
});
</script>

<template>
    <section class="page" aria-labelledby="analytics-title">
        <header class="page-header">
            <div>
                <p class="eyebrow">Operations</p>
                <h1 id="analytics-title">Allocation analytics</h1>
                <p class="page-description">A 30-minute view of the previous UTC day at the matching time. All times are UTC.</p>
            </div>
            <span class="count-badge">{{ snapshots.length }} snapshots</span>
        </header>

        <section class="data-card analytics-filters" aria-label="Analytics filters">
            <div class="analytics-filter-grid">
                <label class="form-field">
                    Airport
                    <select v-model="selectedAirportId" :disabled="isLoadingFilters || airports.length === 0">
                        <option v-for="airport in airports" :key="airport.id" :value="airport.id">
                            {{ airport.code }} — {{ airport.name }}
                        </option>
                    </select>
                </label>
                <label class="form-field">
                    Snapshot day
                    <select v-model="selectedDate" :disabled="isLoadingFilters || dates.length === 0">
                        <option v-for="date in dates" :key="date" :value="date">{{ date }}</option>
                    </select>
                </label>
                <button
                    class="button button--secondary"
                    type="button"
                    :disabled="isLoadingFilters || isRebuilding || !selectedAirportId || !selectedDate"
                    @click="rebuildSnapshots"
                >
                    {{ isRebuilding ? 'Rebuilding...' : 'Rebuild selected day' }}
                </button>
            </div>
        </section>

        <div v-if="isLoadingFilters || isLoadingSnapshots" class="data-card empty-state">Loading allocation snapshots...</div>

        <div v-else-if="error" class="data-card error-state">
            <p>{{ error }}</p>
            <button class="retry-button" type="button" @click="reloadPage">Try again</button>
        </div>

        <div v-else-if="airports.length === 0" class="data-card empty-state">No managed airports are configured.</div>

        <div v-else-if="dates.length === 0" class="data-card empty-state">No allocation snapshots have been captured yet.</div>

        <div v-else-if="snapshots.length === 0" class="data-card empty-state">No snapshots are available for this airport on {{ selectedDate }}.</div>

        <template v-else>
            <section class="analytics-kpis" aria-label="Latest allocation snapshot">
                <article class="data-card analytics-kpi">
                    <p class="analytics-kpi__label">Free gates</p>
                    <p class="analytics-kpi__value">{{ latestSnapshot.free_gates }} / {{ latestSnapshot.total_gates }}</p>
                    <p class="analytics-kpi__detail">at {{ formatTime(latestSnapshot.captured_at) }} UTC</p>
                </article>
                <article class="data-card analytics-kpi">
                    <p class="analytics-kpi__label">Busy gates</p>
                    <p class="analytics-kpi__value">{{ latestSnapshot.busy_gates }}</p>
                    <p class="analytics-kpi__detail">{{ latestSnapshot.exception_blocked_gates }} exception blocked</p>
                </article>
                <article class="data-card analytics-kpi">
                    <p class="analytics-kpi__label">Delayed flights</p>
                    <p class="analytics-kpi__value">{{ latestSnapshot.delayed_flights }}</p>
                    <p class="analytics-kpi__detail">{{ latestSnapshot.on_time_flights }} on time</p>
                </article>
                <article class="data-card analytics-kpi">
                    <p class="analytics-kpi__label">Unallocated</p>
                    <p class="analytics-kpi__value">{{ latestSnapshot.unallocated_flights }}</p>
                    <p class="analytics-kpi__detail">{{ latestSnapshot.invalid_allocations }} validity issues</p>
                </article>
            </section>

            <section class="analytics-grid">
                <article class="data-card analytics-chart-card">
                    <div class="card-title-row">
                        <div>
                            <h2>Gate state</h2>
                            <p class="chart-subtitle">Free, occupied, exception-blocked, and inactive gates.</p>
                        </div>
                    </div>
                    <div class="chart-content chart-content--tall">
                        <Bar :data="gateChartData" :options="stackedBarOptions" />
                    </div>
                </article>

                <article class="data-card analytics-chart-card">
                    <div class="card-title-row">
                        <div>
                            <h2>Flight allocation state</h2>
                            <p class="chart-subtitle">Cumulative departures up to each UTC snapshot.</p>
                        </div>
                    </div>
                    <div class="chart-content chart-content--tall">
                        <Bar :data="flightChartData" :options="stackedBarOptions" />
                    </div>
                </article>

                <article class="data-card analytics-chart-card analytics-chart-card--wide">
                    <div class="card-title-row">
                        <div>
                            <h2>Allocation validity</h2>
                            <p class="chart-subtitle">Active gate exceptions and allocations that need attention.</p>
                        </div>
                    </div>
                    <div class="chart-content chart-content--tall">
                        <Line :data="healthChartData" :options="lineOptions" />
                    </div>
                </article>
            </section>
        </template>
    </section>
</template>
