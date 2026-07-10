.PHONY: help patch release release-minor release-major audit-release verify-release

PYTHON ?= python3
RELEASE_NAME ?=
VERIFY_ARCHIVE ?=

help:
	@printf '%s\n' \
	  'make patch RELEASE_NAME="..."          Prépare le prochain patch (sans audit reproductible automatique)' \
	  'make release                            Ouvre la préparation interactive patch/minor/major' \
	  'make release-minor RELEASE_NAME="..."  Prépare une mineure, audit obligatoire, package et preuves' \
	  'make release-major RELEASE_NAME="..."  Prépare une majeure, audit obligatoire, package et preuves' \
	  'make audit-release                      Lance manuellement le profil d audit release' \
	  'make verify-release                     Vérifie la dernière archive de release' \
	  'make verify-release VERIFY_ARCHIVE=...  Vérifie une archive ZIP précise'

patch:
	@test -n "$(RELEASE_NAME)" || (echo 'ERREUR: RELEASE_NAME est obligatoire.' >&2; exit 2)
	$(PYTHON) tools/python/operations/deployment/d0_prepare_release.py --bump patch --release-name "$(RELEASE_NAME)" --yes
	$(PYTHON) tools/cms.py release

release:
	$(PYTHON) tools/cms.py release --interactive-prepare

release-minor:
	@test -n "$(RELEASE_NAME)" || (echo 'ERREUR: RELEASE_NAME est obligatoire.' >&2; exit 2)
	$(PYTHON) tools/python/operations/deployment/d0_prepare_release.py --bump minor --release-name "$(RELEASE_NAME)" --yes
	$(PYTHON) tools/cms.py release

release-major:
	@test -n "$(RELEASE_NAME)" || (echo 'ERREUR: RELEASE_NAME est obligatoire.' >&2; exit 2)
	$(PYTHON) tools/python/operations/deployment/d0_prepare_release.py --bump major --release-name "$(RELEASE_NAME)" --yes
	$(PYTHON) tools/cms.py release

audit-release:
	$(PYTHON) tools/cms.py audit --profile release --build

verify-release:
	$(PYTHON) tools/cms.py release --verify-archive $(if $(VERIFY_ARCHIVE),--archive "$(VERIFY_ARCHIVE)",)
