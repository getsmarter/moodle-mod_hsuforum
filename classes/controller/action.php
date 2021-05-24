<?php 

namespace mod_hsuforum\controller;

interface action {
	public function get_action($id);
	public function set_action($id);
	public function delete_action($id);
	public function render_action();
}
