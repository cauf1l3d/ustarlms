"""Declared successor task projection for the immutable pre-review installer validator."""
TASK='\\local_ustar\\task\\reconcile_route_access'


def project(current):
    tasks=current.get('tasks')
    if (current.get('addons',{}).get('local_ustar')!='2026110100'
            or not isinstance(tasks,dict) or tasks.get(TASK)!=[0,0]):
        raise ValueError('route_access_task_fixture')
    # Do not alter captured SQL evidence or any legacy task policy.
    return {**current,'tasks':{key:value for key,value in tasks.items() if key!=TASK}}
