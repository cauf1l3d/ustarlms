<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context=context_system::instance(); require_capability('local/ustar:executive',$context);
$d=\local_ustar\native_data::executive(); $total=max(1,(int)($d['totalPeople']??0)); $coverage=min(100,(int)round(((int)($d['assignedPeople']??0)/$total)*100));
$departments=$d['departments']??[];$maxpeople=1;foreach($departments as $dep)$maxpeople=max($maxpeople,(int)$dep['people']);foreach($departments as &$dep)$dep['width']=min(100,(int)round(((int)$dep['people']/$maxpeople)*100));unset($dep);
$qualification=\local_ustar\analytics::qualification_summary();
function local_ustar_render_org_tree(array $nodes,int $depth=0): string {
    if(!$nodes||$depth>20)return '';$html='<ul class="u-org-tree__level">';
    foreach($nodes as $node){$html.='<li><article class="u-org-node"><span class="u-person-dot">'.s($node['initials']).'</span><div><strong>'.s($node['fullname']).'</strong><span>'.s($node['position']).'</span><small>'.s($node['department']).'</small></div></article>'; if(!empty($node['children']))$html.=local_ustar_render_org_tree($node['children'],$depth+1);$html.='</li>';}
    return $html.'</ul>';
}
$treehtml=local_ustar_render_org_tree(\local_ustar\org::company_tree());
$data=[
 'totalPeople'=>(int)($d['totalPeople']??0),'assignedPeople'=>(int)($d['assignedPeople']??0),'unassignedPeople'=>(int)($d['unassignedPeople']??0),'activeLearners30'=>(int)($d['activeLearners30']??0),'completedCourses30'=>(int)($d['completedCourses30']??0),'reviews30'=>(int)($d['reviews30']??0),'avgReviewScore'=>(float)($d['avgReviewScore']??0),'coverage'=>$coverage,'departments'=>$departments,'hasdepartments'=>!empty($departments),'generated'=>userdate(time(),'%d.%m.%Y %H:%M'),'execicon'=>\local_ustar\ui::icon('executive','u-feature-icon'),
 'workspaceurl'=>has_capability('local/ustar:hr',$context)?(new moodle_url('/local/ustar/workspace.php'))->out(false):'','hasworkspace'=>has_capability('local/ustar:hr',$context),
 'qualified'=>$qualification['qualified'],'withgaps'=>$qualification['withgaps'],'expired'=>$qualification['expired'],'qualcoverage'=>$qualification['coverage'],'topgaps'=>$qualification['topgaps'],'hastopgaps'=>$qualification['hastopgaps'],'orgtreehtml'=>$treehtml,'hasreporting'=>\local_ustar\org::reporting_available(),
];
$PAGE->set_context($context);$PAGE->set_url(new moodle_url('/local/ustar/executive.php'));$PAGE->set_pagelayout('ustar');$PAGE->set_title('Руководство | USTAR Academy');$PAGE->set_heading('USTAR Academy');$output=$PAGE->get_renderer('local_ustar');echo $output->header();echo $output->render_from_template('local_ustar/executive',$data);echo $output->footer();
