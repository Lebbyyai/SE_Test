type NameFields = {
    first_name?: string | null;
    middle_name?: string | null;
    last_name?: string | null;
};

/** Display a separately supplied or explicitly dotted middle initial without changing account data. */
export function profileNameParts(user: NameFields) {
    let firstName = user.first_name?.trim() ?? '';
    const middle = user.middle_name?.trim() ?? '';
    let middleInitial = middle ? `${Array.from(middle)[0].toLocaleUpperCase()}.` : '';
    const trailingInitial = firstName.match(/^(.*?)\s+(\p{L})\.$/u);
    if (trailingInitial && (!middleInitial || trailingInitial[2].toLocaleUpperCase() === Array.from(middle)[0]?.toLocaleUpperCase())) {
        firstName = trailingInitial[1];
        middleInitial ||= `${trailingInitial[2].toLocaleUpperCase()}.`;
    }
    return { firstName, middleInitial, lastName: user.last_name?.trim() ?? '' };
}
