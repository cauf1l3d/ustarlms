(function() {
    'use strict';

    const READY = function() {
        const root = document.getElementById('u-position-workspace');
        const data = window.USTAR_POSITION_WORKSPACE_DATA;

        if (!root || !data) {
            return;
        }

        /*
         * USTAR 2706 — live model owns the full available HR workspace.
         * Resolve the actual shell ancestors from the mounted workspace
         * instead of depending on theme CSS ordering.
         */
        document.body.classList.add('u-positions-workspace-page');

        const realContainer = root.closest('.u-container');
        const realMain = root.closest('.u-main');

        if (realContainer) {
            realContainer.style.setProperty('width', '100%', 'important');
            realContainer.style.setProperty('max-width', 'none', 'important');
            realContainer.style.setProperty('margin-left', '0', 'important');
            realContainer.style.setProperty('margin-right', '0', 'important');
        }

        if (realMain) {
            realMain.style.setProperty('max-width', 'none', 'important');
            realMain.style.setProperty('padding-left', '14px', 'important');
            realMain.style.setProperty('padding-right', '14px', 'important');
        }

        const productRegion = root.closest('.u-product-region');
        const productMain = root.closest('.u-product-main');

        if (productRegion) {
            productRegion.style.setProperty('width', '100%', 'important');
            productRegion.style.setProperty('max-width', 'none', 'important');
            productRegion.style.setProperty('margin-left', '0', 'important');
            productRegion.style.setProperty('margin-right', '0', 'important');
        }

        if (productMain) {
            productMain.style.setProperty('padding-left', '16px', 'important');
            productMain.style.setProperty('padding-right', '16px', 'important');
        }

        root.style.setProperty('width', '100%', 'important');
        root.style.setProperty('max-width', 'none', 'important');

        const people = Array.isArray(data.people) ? data.people : [];
        const positions = Array.isArray(data.positions) ? data.positions : [];
        const skills = Array.isArray(data.skills) ? data.skills : [];
        const materials = Array.isArray(data.materials) ? data.materials : [];
        const departments = Array.isArray(data.departments) ? data.departments : [];

        const positionMap = new Map(positions.map(x => [String(x.id), x]));
        const skillMap = new Map(skills.map(x => [String(x.id), x]));
        const materialMap = new Map(materials.map(x => [String(x.key), x]));
        const personMap = new Map(people.map(x => [String(x.id), x]));

        const state = {
            type: null,
            id: null,
            query: '',
            department: ''
        };

        const el = {
            canvas: root.querySelector('[data-workspace-canvas]'),
            svg: root.querySelector('[data-workspace-lines]'),

            people: root.querySelector('[data-workspace-people]'),
            positions: root.querySelector('[data-workspace-positions]'),
            skills: root.querySelector('[data-workspace-skills]'),
            materials: root.querySelector('[data-workspace-materials]'),

            peopleCount: root.querySelector('[data-people-count]'),
            positionCount: root.querySelector('[data-position-count]'),
            skillCount: root.querySelector('[data-skill-count]'),
            materialCount: root.querySelector('[data-material-count]'),

            search: root.querySelector('[data-workspace-search]'),
            department: root.querySelector('[data-workspace-department]'),
            reset: root.querySelector('[data-workspace-reset]'),
            hint: root.querySelector('[data-workspace-hint]')
        };


        function esc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                }[c];
            });
        }


        function posHasSkill(position, skillid) {
            return (position.skills || []).some(function(item) {
                return String(item.id) === String(skillid);
            });
        }


        function skillLevel(position, skillid) {
            const item = (position.skills || []).find(function(candidate) {
                return String(candidate.id) === String(skillid);
            });

            return item ? Number(item.level || 0) : 0;
        }


        function materialHasSkill(material, skillid) {
            return (material.skillids || []).map(String).includes(String(skillid));
        }


        function basePositionAllowed(position) {
            if (state.department && position.departmentid !== state.department) {
                return false;
            }

            return true;
        }


        function queryMatch(parts) {
            if (!state.query) {
                return true;
            }

            return parts.join(' ').toLowerCase().includes(state.query);
        }


        function relationSets() {
            const related = {
                people: new Set(),
                positions: new Set(),
                skills: new Set(),
                materials: new Set()
            };

            if (!state.type || state.id == null) {
                return related;
            }


            if (state.type === 'person') {
                const person = personMap.get(String(state.id));

                if (!person) {
                    return related;
                }

                related.people.add(String(person.id));

                if (positionMap.has(String(person.positionid))) {
                    related.positions.add(String(person.positionid));

                    const position = positionMap.get(String(person.positionid));

                    (position.skills || []).forEach(function(s) {
                        related.skills.add(String(s.id));
                    });

                    materials.forEach(function(material) {
                        if (String(material.positionid) === String(position.id)) {
                            related.materials.add(String(material.key));
                        }
                    });
                }

                return related;
            }


            if (state.type === 'position') {
                const position = positionMap.get(String(state.id));

                if (!position) {
                    return related;
                }

                related.positions.add(String(position.id));

                people.forEach(function(person) {
                    if (String(person.positionid) === String(position.id)) {
                        related.people.add(String(person.id));
                    }
                });

                (position.skills || []).forEach(function(s) {
                    related.skills.add(String(s.id));
                });

                materials.forEach(function(material) {
                    if (String(material.positionid) === String(position.id)) {
                        related.materials.add(String(material.key));
                    }
                });

                return related;
            }


            if (state.type === 'skill') {
                const skillid = String(state.id);

                related.skills.add(skillid);

                positions.forEach(function(position) {
                    if (!posHasSkill(position, skillid)) {
                        return;
                    }

                    related.positions.add(String(position.id));

                    people.forEach(function(person) {
                        if (String(person.positionid) === String(position.id)) {
                            related.people.add(String(person.id));
                        }
                    });
                });

                materials.forEach(function(material) {
                    if (materialHasSkill(material, skillid)) {
                        related.materials.add(String(material.key));
                    }
                });

                return related;
            }


            if (state.type === 'material') {
                const material = materialMap.get(String(state.id));

                if (!material) {
                    return related;
                }

                related.materials.add(String(material.key));

                const materialSkills = (material.skillids || []).map(String);

                if (materialSkills.length) {
                    materialSkills.forEach(function(skillid) {
                        related.skills.add(skillid);
                    });

                    positions.forEach(function(position) {
                        const usesSkill = materialSkills.some(function(skillid) {
                            return posHasSkill(position, skillid);
                        });

                        if (!usesSkill) {
                            return;
                        }

                        related.positions.add(String(position.id));

                        people.forEach(function(person) {
                            if (String(person.positionid) === String(position.id)) {
                                related.people.add(String(person.id));
                            }
                        });
                    });
                } else {
                    /*
                     * Material exists in a route but has no skill relation.
                     * Preserve its real source position and expose the gap.
                     */
                    related.positions.add(String(material.positionid));

                    people.forEach(function(person) {
                        if (String(person.positionid) === String(material.positionid)) {
                            related.people.add(String(person.id));
                        }
                    });
                }

                return related;
            }

            return related;
        }


        function visibleData() {
            const related = relationSets();
            const selected = Boolean(state.type);

            let visiblePositions = positions.filter(basePositionAllowed);

            if (selected) {
                visiblePositions = visiblePositions.filter(function(position) {
                    return related.positions.has(String(position.id));
                });
            }

            visiblePositions = visiblePositions.filter(function(position) {
                const names = (position.skills || []).map(function(item) {
                    const skill = skillMap.get(String(item.id));
                    return skill ? skill.name : '';
                });

                return queryMatch([
                    position.name,
                    position.department,
                    names.join(' ')
                ]);
            });


            const allowedPositionIds = new Set(
                visiblePositions.map(function(position) {
                    return String(position.id);
                })
            );


            let visiblePeople = people.filter(function(person) {
                if (state.department && person.departmentid !== state.department) {
                    return false;
                }

                if (selected && !related.people.has(String(person.id))) {
                    return false;
                }

                if (
                    person.positionknown &&
                    selected &&
                    !allowedPositionIds.has(String(person.positionid))
                ) {
                    return false;
                }

                return queryMatch([
                    person.name,
                    person.positionname,
                    person.department
                ]);
            });


            let visibleSkills = skills.filter(function(skill) {
                if (selected && !related.skills.has(String(skill.id))) {
                    return false;
                }

                if (!selected) {
                    const used = visiblePositions.some(function(position) {
                        return posHasSkill(position, skill.id);
                    });

                    if (!used) {
                        return false;
                    }
                }

                return queryMatch([
                    skill.name,
                    skill.category
                ]);
            });


            let visibleMaterials = materials.filter(function(material) {
                if (selected && !related.materials.has(String(material.key))) {
                    return false;
                }

                if (!selected) {
                    return false;
                }

                const position = positionMap.get(String(material.positionid));

                return queryMatch([
                    material.name,
                    material.typelabel,
                    position ? position.name : '',
                    (material.skillids || []).map(function(skillid) {
                        const skill = skillMap.get(String(skillid));
                        return skill ? skill.name : '';
                    }).join(' ')
                ]);
            });


            return {
                related: related,
                people: visiblePeople,
                positions: visiblePositions,
                skills: visibleSkills,
                materials: visibleMaterials
            };
        }


        function personCard(person) {
            const selected =
                state.type === 'person' &&
                String(state.id) === String(person.id);

            return [
                '<button type="button"',
                ' class="u-position-workspace__card u-position-workspace__card--person',
                selected ? ' is-selected' : '',
                person.positionknown ? '' : ' is-gap',
                '" data-person-card="', esc(person.id), '">',
                '<strong>', esc(person.name), '</strong>',
                '<span>', esc(person.positionname), '</span>',
                '<small>', esc(person.department || 'Без подразделения'), '</small>',
                '</button>'
            ].join('');
        }


        function positionCard(position) {
            const selected =
                state.type === 'position' &&
                String(state.id) === String(position.id);

            return [
                '<article class="u-position-workspace__card u-position-workspace__card--position',
                selected ? ' is-selected' : '',
                '" data-position-wrap="', esc(position.id), '">',

                '<button type="button" class="u-position-workspace__card-main"',
                ' data-position-card="', esc(position.id), '">',
                '<strong>', esc(position.name), '</strong>',
                '<span>', esc(position.department), '</span>',
                '<small>',
                esc(position.peoplecount), ' сотрудников · ',
                esc(position.skillcount), ' навыков',
                '</small>',
                '</button>',

                '<div class="u-position-workspace__actions">',
                '<a href="', esc(position.editurl), '">Редактировать</a>',
                '<a href="', esc(position.routeurl), '">Маршрут</a>',
                '</div>',

                '</article>'
            ].join('');
        }


        function skillCard(skill) {
            const selected =
                state.type === 'skill' &&
                String(state.id) === String(skill.id);

            let level = '';

            if (state.type === 'position') {
                const position = positionMap.get(String(state.id));

                if (position) {
                    const value = skillLevel(position, skill.id);

                    if (value) {
                        level = ' · уровень ' + value;
                    }
                }
            }

            return [
                '<button type="button"',
                ' class="u-position-workspace__card u-position-workspace__card--skill',
                selected ? ' is-selected' : '',
                '" data-skill-card="', esc(skill.id), '">',
                '<strong>', esc(skill.name), '</strong>',
                '<span>', esc(skill.category || 'Навык'), '</span>',
                '<small>',
                esc(skill.affectedcount), ' должн.',
                esc(level),
                '</small>',
                '</button>'
            ].join('');
        }


        function materialCard(material) {
            const selected =
                state.type === 'material' &&
                String(state.id) === String(material.key);

            const position = positionMap.get(String(material.positionid));

            return [
                '<article tabindex="0"',
                ' class="u-position-workspace__card u-position-workspace__card--material',
                selected ? ' is-selected' : '',
                material.unlinked ? ' is-gap' : '',
                '" data-material-card="', esc(material.key), '">',
                '<button type="button" class="u-position-workspace__card-main"',
                ' data-material-select="', esc(material.key), '">',
                '<strong>', esc(material.name), '</strong>',
                '<span>',
                esc(material.typelabel),
                position ? ' · ' + esc(position.name) : '',
                '</span>',
                material.unlinked
                    ? '<small class="u-position-workspace__warning">Навык не назначен</small>'
                    : '<small>' + esc((material.skillids || []).length) + ' связанных навыков</small>',
                '</button>',
                '<div class="u-position-workspace__actions">',
                '<a href="', esc(material.url), '">Открыть</a>',
                '<a href="', esc(material.routeurl), '">Route Studio</a>',
                '</div>',
                '</article>'
            ].join('');
        }


        function empty(text) {
            return '<div class="u-position-workspace__empty">' + esc(text) + '</div>';
        }


        function updateHint() {
            if (!state.type) {
                el.hint.textContent =
                    'Выберите сотрудника, должность, навык или материал';
                return;
            }

            if (state.type === 'person') {
                const person = personMap.get(String(state.id));
                el.hint.textContent =
                    person
                        ? person.name + ' → должность → навыки → материалы'
                        : '';
                return;
            }

            if (state.type === 'position') {
                const position = positionMap.get(String(state.id));
                el.hint.textContent =
                    position
                        ? position.name + ': сотрудники, требования и обучение'
                        : '';
                return;
            }

            if (state.type === 'skill') {
                const skill = skillMap.get(String(state.id));
                el.hint.textContent =
                    skill
                        ? skill.name + ': все должности и сотрудники, которым он требуется'
                        : '';
                return;
            }

            if (state.type === 'material') {
                const material = materialMap.get(String(state.id));
                el.hint.textContent =
                    material
                        ? material.name + ': обратная трассировка материала'
                        : '';
            }
        }


        function render() {
            const view = visibleData();

            el.people.innerHTML =
                view.people.length
                    ? view.people.map(personCard).join('')
                    : empty('Нет связанных сотрудников');

            el.positions.innerHTML =
                view.positions.length
                    ? view.positions.map(positionCard).join('')
                    : empty('Нет связанных должностей');

            el.skills.innerHTML =
                view.skills.length
                    ? view.skills.map(skillCard).join('')
                    : empty('Нет связанных навыков');

            el.materials.innerHTML =
                view.materials.length
                    ? view.materials.map(materialCard).join('')
                    : empty(
                        state.type
                            ? 'Связанные опубликованные материалы пока не найдены'
                            : 'Выберите объект слева, чтобы показать материалы'
                    );

            el.peopleCount.textContent = String(view.people.length);
            el.positionCount.textContent = String(view.positions.length);
            el.skillCount.textContent = String(view.skills.length);
            el.materialCount.textContent = String(view.materials.length);

            root.classList.toggle('has-selection', Boolean(state.type));

            updateHint();
            queueDraw();
        }


        function select(type, id) {
            if (
                state.type === type &&
                String(state.id) === String(id)
            ) {
                state.type = null;
                state.id = null;
            } else {
                state.type = type;
                state.id = id;
            }

            render();
        }


        root.addEventListener('click', function(event) {
            if (event.target.closest('a')) {
                return;
            }

            const person = event.target.closest('[data-person-card]');
            if (person) {
                select('person', person.dataset.personCard);
                return;
            }

            const position = event.target.closest('[data-position-card]');
            if (position) {
                select('position', position.dataset.positionCard);
                return;
            }

            const skill = event.target.closest('[data-skill-card]');
            if (skill) {
                select('skill', skill.dataset.skillCard);
                return;
            }

            const material = event.target.closest('[data-material-select]');
            if (material) {
                select('material', material.dataset.materialSelect);
            }
        });


        if (el.reset) {
            el.reset.addEventListener('click', function() {
                state.type = null;
                state.id = null;
                state.query = '';
                state.department = '';

                if (el.search) {
                    el.search.value = '';
                }

                if (el.department) {
                    el.department.value = '';
                }

                render();
            });
        }


        if (el.search) {
            el.search.addEventListener('input', function() {
                state.query = el.search.value.trim().toLowerCase();
                render();
            });
        }


        if (el.department) {
            el.department.innerHTML =
                '<option value="">Все подразделения</option>' +
                departments.map(function(department) {
                    return [
                        '<option value="', esc(department.id), '">',
                        esc(department.name),
                        '</option>'
                    ].join('');
                }).join('');

            el.department.addEventListener('change', function() {
                state.department = el.department.value;
                state.type = null;
                state.id = null;
                render();
            });
        }


        function svgPoint(node, side) {
            const container = el.canvas.getBoundingClientRect();
            const box = node.getBoundingClientRect();

            return {
                x:
                    (side === 'right' ? box.right : box.left)
                    - container.left
                    + el.canvas.scrollLeft,
                y:
                    box.top
                    - container.top
                    + el.canvas.scrollTop
                    + box.height / 2
            };
        }


        function path(from, to) {
            const delta = Math.max(32, Math.abs(to.x - from.x) * 0.42);

            return [
                'M ', from.x, ' ', from.y,
                ' C ', from.x + delta, ' ', from.y,
                ', ', to.x - delta, ' ', to.y,
                ', ', to.x, ' ', to.y
            ].join('');
        }


        function addLine(lines, source, target, className) {
            if (!source || !target) {
                return;
            }

            lines.push(
                '<path class="u-position-workspace__line ' +
                className +
                '" d="' +
                path(
                    svgPoint(source, 'right'),
                    svgPoint(target, 'left')
                ) +
                '"></path>'
            );
        }


        let frame = 0;

        function queueDraw() {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(drawLines);
        }


        function drawLines() {
            if (!el.svg || !state.type) {
                if (el.svg) {
                    el.svg.innerHTML = '';
                }
                return;
            }

            el.svg.setAttribute('width', String(el.canvas.scrollWidth));
            el.svg.setAttribute('height', String(el.canvas.scrollHeight));
            el.svg.style.width = el.canvas.scrollWidth + 'px';
            el.svg.style.height = el.canvas.scrollHeight + 'px';

            const lines = [];


            /*
             * EMPLOYEE → POSITION
             */
            root.querySelectorAll('[data-person-card]').forEach(function(personNode) {
                const person = personMap.get(String(personNode.dataset.personCard));

                if (!person || !person.positionknown) {
                    return;
                }

                const positionNode = root.querySelector(
                    '[data-position-card="' +
                    CSS.escape(String(person.positionid)) +
                    '"]'
                );

                addLine(lines, personNode, positionNode, 'is-direct');
            });


            /*
             * POSITION → SKILL
             */
            root.querySelectorAll('[data-position-card]').forEach(function(positionNode) {
                const position = positionMap.get(String(positionNode.dataset.positionCard));

                if (!position) {
                    return;
                }

                (position.skills || []).forEach(function(skillref) {
                    const skillNode = root.querySelector(
                        '[data-skill-card="' +
                        CSS.escape(String(skillref.id)) +
                        '"]'
                    );

                    addLine(lines, positionNode, skillNode, 'is-direct');
                });
            });


            /*
             * SKILL → MATERIAL
             */
            root.querySelectorAll('[data-material-select]').forEach(function(materialNode) {
                const material = materialMap.get(String(materialNode.dataset.materialSelect));

                if (!material) {
                    return;
                }

                if ((material.skillids || []).length) {
                    material.skillids.forEach(function(skillid) {
                        const skillNode = root.querySelector(
                            '[data-skill-card="' +
                            CSS.escape(String(skillid)) +
                            '"]'
                        );

                        addLine(lines, skillNode, materialNode, 'is-learning');
                    });
                } else {
                    /*
                     * Published route content with no skill relation:
                     * show the missing model link explicitly.
                     */
                    const positionNode = root.querySelector(
                        '[data-position-card="' +
                        CSS.escape(String(material.positionid)) +
                        '"]'
                    );

                    addLine(lines, positionNode, materialNode, 'is-gap');
                }
            });

            el.svg.innerHTML = lines.join('');
        }


        el.canvas.addEventListener('scroll', queueDraw, {passive: true});
        window.addEventListener('scroll', queueDraw, {passive: true});
        window.addEventListener('resize', queueDraw, {passive: true});

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(queueDraw);

            observer.observe(el.canvas);
            observer.observe(el.people);
            observer.observe(el.positions);
            observer.observe(el.skills);
            observer.observe(el.materials);
        }

        render();
    };


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', READY, {once: true});
    } else {
        READY();
    }
})();
