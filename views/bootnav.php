<a href="config.php?display=conferences" class="list-group-item <?php echo ($request['view'] == ''? 'hidden':'')?>"><i class="fa fa-list"></i>&nbsp; <?php echo _("List Conferences") ?></a>
<a href="config.php?display=conferences&view=form" class="list-group-item <?php echo ($request['view'] == 'form'? 'hidden':'')?>" ><i class="fa fa-plus"></i>&nbsp; <?php echo _("Add Conference") ?></a>