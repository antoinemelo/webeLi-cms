# Kit de prompts — clarification des Blueprints

Ces prompts sont conçus pour être soumis **un par un, dans l'ordre**. Ne soumettez le prompt suivant qu'après avoir relu le compte rendu, testé le résultat et validé le diff du précédent.

Ordre recommandé :

1. `01-audit-et-plan.md` — établir une référence sans modifier le code.
2. `02-clarification-ux.md` — améliorer vocabulaire, navigation et divulgation progressive.
3. `03-editeur-structure.md` — clarifier la hiérarchie sections/champs/fieldsets.
4. `04-configuration-champs.md` — remplacer progressivement le JSON courant par des contrôles adaptés.
5. `05-securite-fieldsets-et-versions.md` — rendre les impacts, brouillons et activations explicites.
6. `06-qualification-finale.md` — tests, documentation et audit final, sans nouvelle refonte.

## Garde-fous d'utilisation

- Créez idéalement une branche Git dédiée avant de commencer.
- Conservez un commit séparé par phase.
- N'acceptez pas une phase si les tests existants régressent ou si le compte rendu ne liste pas clairement les fichiers modifiés.
- Si une phase révèle que le backend ou le modèle de données doit changer, demandez d'abord une proposition détaillée. Ne laissez pas l'agent improviser une migration.
- Les prompts interdisent les réécritures globales et demandent de préserver les API, les données existantes et les chemins d'installation variables (`/mod`, `/eve`, `/edu`, etc.).

