# Coordinator panel availability

In Defense Requests > Details > Assign & Schedule, enter a date, start time, and end time first (Philippine time). The panel selectors then show availability for that interval, the number of recorded defenses on the selected date, and other same-day time slots. Overlapping options stay visible but cannot be selected. There is no daily defense limit.

Counts include completed defenses and count each request only once per person, even if the same email holds adviser and panel roles. A saved current request counts once but never conflicts with itself. Cancelled/rejected defenses do not count. Cancelled, rejected, or completed defenses do not block an upcoming interval. Bookings outside this system are not inferred.

Changing the date or time immediately suspends selection while availability refreshes, preserves existing choices, and flags selections that are now conflicting. The assigned adviser's conflicts are shown separately. Failed availability checks block saving and offer Retry. The endpoint is restricted to authorized staff and coordinators assigned to the request; it returns booking times without other students' names or thesis titles.

Save Schedule saves panel choices and scheduling details together. This also allows changing the time and panel roster together for an already scheduled defense. Save Panel Assignment checks the draft interval without committing that date/time. The scheduling mutex and server-side conflict checks still apply to both actions. No migration is needed.

Manual verification:
1. Enter a slot overlapping another defense and open a panel dropdown: busy people show their conflicting times and cannot be selected.
2. Move to an adjacent or non-overlapping slot: the same people become selectable, retaining same-day booking counts.
3. Select people, then change time to conflict: selections stay visible with warnings and saves are blocked until resolved.
4. Save Schedule with new panel choices: reload and verify the roster, date/time, and daily count (the current request appears only once).
5. Test multiple defenses that day: workload counts remain informational, without a four-defense cap.

Regression tests: tests/Feature/PanelAvailabilityTest.php. Tests use isolated data, not the local demo database. Live browser acceptance remains a separate check.
