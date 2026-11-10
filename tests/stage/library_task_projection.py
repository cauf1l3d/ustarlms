"""Validate only the two declared successor tasks before calling an immutable old validator."""
TASK='\\local_ustar\\task\\sync_library_quick_checks'
ROUTE_TASK='\\local_ustar\\task\\reconcile_route_access'
def project(current):
    tasks=current.get('tasks')
    if (current.get('addons',{}).get('local_ustar')!='2026110200'
            or current.get('addons',{}).get('theme_ustar')!='2026110200'
            or not isinstance(tasks,dict) or tasks.get(TASK)!=[0,0] or tasks.get(ROUTE_TASK)!=[0,0]):
        raise ValueError('library_task_fixture')
    return {**current,'tasks':{k:v for k,v in tasks.items() if k not in (TASK,ROUTE_TASK)}}
