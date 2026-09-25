<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import PaginationControls from '../components/PaginationControls.vue';

const airports = ref([]);
const dates = ref([]);
const schedules = ref([]);
const pagination = ref({ currentPage: 1, lastPage: 1, total: 0 });
const selectedAirportId = ref('');
const selectedDate = ref('');
const gate = ref('');
const search = ref('');
const error = ref('');
const isLoadingFilters = ref(true);
const isLoadingSchedules = ref(false);
const filtersReady = ref(false);

const selectedAirport = computed(() => airports.value.find((airport) => airport.id === Number(selectedAirportId.value)));

async function loadFilters() {
    isLoadingFilters.value = true;
    error.value = '';

    try {
        const response = await fetch('/api/gate-schedule/filters', {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load schedule filters.');
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

async function loadSchedules(page = 1) {
    if (! filtersReady.value || ! selectedAirportId.value || ! selectedDate.value) {
        schedules.value = [];
        pagination.value = { currentPage: 1, lastPage: 1, total: 0 };

        return;
    }

    isLoadingSchedules.value = true;
    error.value = '';

    try {
        const query = new URLSearchParams({
            airport_id: selectedAirportId.value,
            date: selectedDate.value,
            page,
        });

        if (search.value) {
            query.set('search', search.value);
        }

        if (gate.value) {
            query.set('gate', gate.value);
        }

        const response = await fetch(`/api/gate-schedules?${query}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load the gate schedule.');
        }

        const payload = await response.json();
        schedules.value = payload.data;
        pagination.value = {
            currentPage: payload.current_page,
            lastPage: payload.last_page,
            total: payload.total,
        };
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isLoadingSchedules.value = false;
    }
}

async function reloadPage() {
    await loadFilters();
    await loadSchedules();
}

function formatDateTime(value) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
        hour12: false,
        timeZone: 'UTC',
    }).format(new Date(value));
}

function formatDelay(minutes) {
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    if (hours > 0 && remainingMinutes > 0) {
        return `${hours} ${hours === 1 ? 'hour' : 'hours'} and ${remainingMinutes} minutes`;
    }

    if (hours > 0) {
        return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    return `${remainingMinutes} minutes`;
}

function goToPage(page) {
    if (page >= 1 && page <= pagination.value.lastPage) {
        loadSchedules(page);
    }
}

watch([selectedAirportId, selectedDate], loadSchedules);
onMounted(async () => {
    await loadFilters();
    await loadSchedules();
});
</script>

<template>
    <section class="page" aria-labelledby="gate-schedule-title">
        <header class="page-header">
            <div>
                <p class="eyebrow">Operations</p>
                <h1 id="gate-schedule-title">Gate schedule</h1>
                <p class="page-description">Allocated departure gates, occupancy periods, and operational delays. All times are UTC.</p>
            </div>
            <span class="count-badge">{{ pagination.total }} {{ pagination.total === 1 ? 'allocation' : 'allocations' }}</span>
        </header>

        <section class="data-card schedule-filters" aria-label="Schedule filters">
            <div class="filter-grid gate-schedule-filter-grid">
                <label class="form-field">
                    Airport
                    <select v-model="selectedAirportId" :disabled="isLoadingFilters || airports.length === 0">
                        <option v-for="airport in airports" :key="airport.id" :value="airport.id">
                            {{ airport.code }} — {{ airport.name }}
                        </option>
                    </select>
                </label>
                <label class="form-field">
                    Date
                    <select v-model="selectedDate" :disabled="isLoadingFilters || dates.length === 0">
                        <option v-for="date in dates" :key="date" :value="date">{{ date }}</option>
                    </select>
                </label>
                <label class="form-field" for="schedule-gate">
                    Gate
                    <input id="schedule-gate" v-model.trim="gate" type="search" placeholder="e.g. A1">
                </label>
                <form class="schedule-search" @submit.prevent="loadSchedules">
                    <label class="form-field" for="schedule-search">
                        Search destination airport or city
                        <input id="schedule-search" v-model.trim="search" type="search" placeholder="e.g. London, EGLL">
                    </label>
                    <button class="button button--secondary" type="submit" :disabled="isLoadingSchedules">Search</button>
                </form>
            </div>
        </section>

        <div class="data-card schedule-card">
            <div v-if="isLoadingFilters || isLoadingSchedules" class="empty-state">Loading gate schedule...</div>

            <div v-else-if="error" class="error-state">
                <p>{{ error }}</p>
                <button class="retry-button" type="button" @click="reloadPage">Try again</button>
            </div>

            <div v-else-if="airports.length === 0" class="empty-state">No managed airports are configured.</div>

            <div v-else-if="dates.length === 0" class="empty-state">No gate allocations are available yet.</div>

            <div v-else-if="schedules.length === 0" class="empty-state">
                No allocations for {{ selectedAirport?.code }} on {{ selectedDate }}.
            </div>

            <div v-else class="table-scroll">
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th scope="col">Gate</th>
                            <th scope="col">Destination</th>
                            <th scope="col">Callsign</th>
                            <th scope="col">Estimated departure</th>
                            <th scope="col">Occupancy period</th>
                            <th scope="col">Delay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="schedule in schedules" :key="schedule.id" :class="{ 'schedule-row--delayed': schedule.delay_minutes > 0 }">
                            <td><span class="airport-code">{{ schedule.gate_code }}</span></td>
                            <td>
                                <template v-if="schedule.destination">
                                    <span class="destination-name">{{ schedule.destination.name }}</span>
                                    <span class="destination-location">{{ schedule.destination.code }} · {{ schedule.destination.city }}, {{ schedule.destination.country }}</span>
                                </template>
                                <span v-else class="destination-location">
                                    {{ schedule.arrival_external_airport_code ? `Destination unavailable (${schedule.arrival_external_airport_code})` : 'Destination unavailable' }}
                                </span>
                            </td>
                            <td>{{ schedule.callsign || '—' }}</td>
                            <td>{{ schedule.estimated_departure_at ? formatDateTime(schedule.estimated_departure_at) : 'â€”' }}</td>
                            <td>
                                <span class="schedule-period">{{ formatDateTime(schedule.occupied_from) }}</span>
                                <span class="schedule-period">to {{ formatDateTime(schedule.occupied_until) }}</span>
                            </td>
                            <td>
                                <span v-if="schedule.delay_minutes > 0" class="delay-indicator">
                                    <span class="delay-warning" aria-hidden="true">!</span>
                                    {{ formatDelay(schedule.delay_minutes) }}
                                </span>
                                <span v-else>—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <PaginationControls
                    v-if="pagination.lastPage > 1"
                    :current-page="pagination.currentPage"
                    :last-page="pagination.lastPage"
                    @change="goToPage"
                />
            </div>
        </div>
    </section>
</template>
