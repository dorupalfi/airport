<script setup>
const isGateSchedulePage = window.location.pathname === '/gate-schedule';
const isUnallocatedFlightsPage = window.location.pathname === '/unallocated-flights';
const isAnalyticsPage = window.location.pathname === '/analytics';
const isAirportsPage = !isGateSchedulePage && !isUnallocatedFlightsPage && !isAnalyticsPage;
</script>

<template>
    <aside class="sidebar">
        <a class="brand" href="/" aria-label="Flight Gate Scheduler home">
            <span class="brand-mark">FG</span>
            <span>Flight Gate<br><strong>Scheduler</strong></span>
        </a>

        <nav class="navigation" aria-label="Main navigation">
            <a class="navigation-link" :class="{ 'navigation-link--active': isAirportsPage }" href="/airports" :aria-current="isAirportsPage ? 'page' : undefined">
                <span class="navigation-icon">AP</span>
                Airports
            </a>
            <a class="navigation-link" :class="{ 'navigation-link--active': isGateSchedulePage }" href="/gate-schedule" :aria-current="isGateSchedulePage ? 'page' : undefined">
                <span class="navigation-icon">GS</span>
                Gate schedule
            </a>
            <a class="navigation-link" :class="{ 'navigation-link--active': isUnallocatedFlightsPage }" href="/unallocated-flights" :aria-current="isUnallocatedFlightsPage ? 'page' : undefined">
                <span class="navigation-icon">UF</span>
                Unallocated flights
            </a>
            <a class="navigation-link" :class="{ 'navigation-link--active': isAnalyticsPage }" href="/analytics" :aria-current="isAnalyticsPage ? 'page' : undefined">
                <span class="navigation-icon">AN</span>
                Analytics
            </a>
        </nav>
    </aside>
</template>
