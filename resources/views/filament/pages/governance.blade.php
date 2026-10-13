<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">How to read this</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            KPIs follow the TechOps PDR (§10) RAG thresholds. Register-scope rows only
            (Incident classification, metric-eligible severity). The PDR's "Negative MTTR"
            KPI is replaced by <strong>FL Usable Timing</strong> — negative MTTR is the
            day-unit encoding for fund-status rows, not a defect.
        </p>
    </x-filament-panels::section>
</x-filament-panels::page>
