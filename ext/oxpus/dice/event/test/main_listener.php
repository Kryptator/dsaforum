<?php

/**
*
* @package phpBB Extension - DICE
* @copyright (c) 2017 OXPUS - www.oxpus.net
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace oxpus\dice\event;

/**
* @ignore
*/
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class main_listener implements EventSubscriberInterface
{
	static public function getSubscribedEvents()
	{
		return array(
			'core.user_setup'							=> 'load_language_on_setup',
			'core.delete_user_after'					=> 'delete_user',
			'core.delete_posts_in_transaction_before'	=> 'delete_dice_for_posts',
			'core.acp_manage_forums_request_data'		=> 'save_forum_enable_dice',
			'core.acp_manage_forums_initialise_data'	=> 'init_forum_enable_dice',
			'core.acp_manage_forums_display_form'		=> 'forum_dice_template_data',
			'core.permissions'							=> 'add_permission_cat',
			'core.modify_posting_auth'					=> 'fix_set_forum_id',
			'core.posting_modify_message_text'			=> 'prepare_post_text_before_format',
			'core.posting_modify_submit_post_before'	=> 'prepare_post_text_for_save',
			'core.posting_modify_submit_post_after'		=> 'check_and_role_dices',
			'core.display_custom_bbcodes_modify_sql'	=> 'filter_bbcodes',
			'core.viewtopic_modify_page_title'			=> 'quick_reply_bbcodes',
			'core.viewtopic_before_f_read_check'		=> 'toggle_rem_init_rolls',
			'core.viewtopic_modify_post_data'			=> 'format_dice_rolls',
			'core.viewtopic_modify_post_row'			=> 'display_rolls',
			'core.decode_message_after'					=> 'decode_post_text'
		);
	}

	/* @var string phpbb_root_path */
	protected $root_path;

	/* @var string phpEx */
	protected $php_ext;

	/* @var string table_prefix */
	protected $table_prefix;

	/* @var \phpbb\extension\manager */
	protected $phpbb_extension_manager;

	/* @var \phpbb\path_helper */
	protected $phpbb_path_helper;

	/* @var Container */
	protected $phpbb_container;

	/* @var \phpbb\db\driver\driver_interface */
	protected $db;

	/* @var \phpbb\config\config */
	protected $config;

	/* @var \phpbb\controller\helper */
	protected $helper;

	/* @var \phpbb\auth\auth */
	protected $auth;

	/* @var \phpbb\template\template */
	protected $template;

	/* @var \phpbb\user */
	protected $user;

	/** @var \phpbb\language\language $language Language object */
	protected $language;

	protected $request;
	protected $forum_id = 0;
	protected $post_id = 0;
	protected $bbcode_uid;
	protected $dice_table;
	protected $dice_char;
	protected $ext_path;
	protected $ext_path_web;
	protected $dice_roll_data = array();
	protected $dice_roll = array();

	/**
	* Constructor
	*
	* @param string									$root_path
	* @param string									$php_ext
	* @param string									$table_prefix
	* @param \phpbb\extension\manager				$phpbb_extension_manager
	* @param \phpbb\path_helper						$phpbb_path_helper
	* @param Container								$phpbb_container
	* @param \phpbb\db\driver\driver_interfacer		$db
	* @param \phpbb\config\config					$config
	* @param \phpbb\controller\helper				$helper
	* @param \phpbb\auth\auth						$auth
	* @param \phpbb\template\template				$template
	* @param \phpbb\user							$user
	* @param \phpbb\language\language				$language
	*/
	public function __construct($root_path, $php_ext, $table_prefix, \phpbb\extension\manager $phpbb_extension_manager, \phpbb\path_helper $phpbb_path_helper, Container $phpbb_container, \phpbb\db\driver\driver_interface $db, \phpbb\config\config $config, \phpbb\controller\helper $helper, \phpbb\auth\auth $auth, \phpbb\template\template $template, \phpbb\user $user, \phpbb\language\language $language)
	{
		$this->root_path				= $root_path;
		$this->php_ext 					= $php_ext;
		$this->table_prefix 			= $table_prefix;
		$this->phpbb_extension_manager	= $phpbb_extension_manager;
		$this->phpbb_path_helper		= $phpbb_path_helper;
		$this->phpbb_container 			= $phpbb_container;
		$this->db 						= $db;
		$this->config 					= $config;
		$this->helper 					= $helper;
		$this->auth						= $auth;
		$this->template 				= $template;
		$this->user 					= $user;
		$this->language					= $language;

		$this->request					= $phpbb_container->get('request');
		$this->dice_table				= $this->table_prefix . 'dice';
		$this->ext_path					= $this->phpbb_extension_manager->get_extension_path('oxpus/dice', true);
		$this->ext_path_web				= $this->phpbb_path_helper->update_web_root_path($this->ext_path);
	}

	public function load_language_on_setup($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = array(
			'ext_name' => 'oxpus/dice',
			'lang_set' => 'common',
		);

		if (defined('ADMIN_START'))
		{
			$lang_set_ext[] = array(
				'ext_name' => 'oxpus/dice',
				'lang_set' => 'permissions_dice',
			);
		}

		$event['lang_set_ext'] = $lang_set_ext;
	}

	public function quick_reply_bbcodes($event)
	{
		if ($this->phpbb_extension_manager->is_enabled('boardtools/quickreply'))
		{
			return;
		}

		$sql = 'SELECT forum_allow_dice FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $event['forum_id'];
		$result = $this->db->sql_query($sql);
		$forum_allow_dice = $this->db->sql_fetchfield('forum_allow_dice');
		$this->db->sql_freeresult($result);

		if(($forum_allow_dice == 2) || ($forum_allow_dice == 1 && $this->auth->acl_get('f_allow_dice', $event['forum_id'])))
		{
			$this->language->add_lang('posting');

			$sql_ary = array(
				'SELECT'	=> 'b.bbcode_id, b.bbcode_tag, b.bbcode_helpline',
				'FROM'		=> array(BBCODES_TABLE => 'b'),
				'WHERE'		=> 'b.display_on_posting = 1 AND ' . $this->db->sql_in_set('bbcode_tag', array('dice', 'dice=')),
				'ORDER_BY'	=> 'b.bbcode_tag',
			);

			$result = $this->db->sql_query($this->db->sql_build_query('SELECT', $sql_ary));

			$i = 0;
			$num_predefined_bbcodes = NUM_PREDEFINED_BBCODES;

			while ($row = $this->db->sql_fetchrow($result))
			{
				$row['bbcode_helpline'] = $this->language->lang(strtoupper($row['bbcode_helpline']));

				$bbcode_tag_open	= "'[{$row['bbcode_tag']}]'";
				$bbcode_tag_close	= "'[/" . str_replace('=', '', $row['bbcode_tag']) . "]'";

				$custom_tags = array(
					'BBCODE_NAME'		=> "'[{$row['bbcode_tag']}]', '[/" . str_replace('=', '', $row['bbcode_tag']) . "]'",
					'BBCODE_ID'			=> $num_predefined_bbcodes + ($i * 2),
					'BBCODE_ID_CLOSE'	=> $num_predefined_bbcodes + ($i * 2) + 1,
					'BBCODE_TAG'		=> $row['bbcode_tag'],
					'BBCODE_TAG_CLEAN'	=> str_replace('=', '-', $row['bbcode_tag']),
					'BBCODE_HELPLINE'	=> $row['bbcode_helpline'],
					'BBCODE_OPEN_TAG'	=> $bbcode_tag_open,
					'BBCODE_CLOSE_TAG'	=> $bbcode_tag_close,
					'A_BBCODE_HELPLINE'	=> str_replace(array('&amp;', '&quot;', "'", '&lt;', '&gt;'), array('&', '"', "\'", '<', '>'), $row['bbcode_helpline']),
				);

				$this->template->assign_block_vars('custom_dice_tags', $custom_tags);

				$i++;
			}
			$this->db->sql_freeresult($result);

			$this->template->assign_vars(array(
				'DICE_JS_PATH'			=> $this->ext_path_web . 'includes/js/',
				'S_ENABLE_DICE_BUTTONS'	=> true,
			));
		}
	}

	public function toggle_rem_init_rolls($event)
	{
		$removability	= $this->request->variable('removability', -1);
		$diceID			= $this->request->variable('diceID', -1);

		if($removability >= 0 && $diceID >= 0 && $this->auth->acl_get('m_', $event['forum_id']))
		{
			$this->_setremovability($diceID, $removability);
		}

		$topic_id = $event['topic_id'];

		$sql = 'SELECT r.*, u.username FROM ' . $this->dice_table . ' r, ' . POSTS_TABLE . ' p, ' . USERS_TABLE . ' u
				WHERE p.topic_id = ' . (int) $topic_id . '
					AND r.post_id = p.post_id
					AND u.user_id = r.user_id';
		$result = $this->db->sql_query($sql);
		$total_roll_data = $this->db->sql_affectedrows($result);

		if ($total_roll_data)
		{
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->dice_roll_data[$row['post_id']][$row['roll_id']] = $row;
			}
		}

		$this->db->sql_freeresult($result);
	}

	public function delete_user($event)
	{
		// Delete the miscellaneous (non-post) data for the user
		$sql = 'DELETE FROM ' . $this->dice_table . ' WHERE ' . $this->db->sql_in_set('user_id', $event['user_ids']);
		$this->db->sql_query($sql);
	}

	public function delete_dice_for_posts($event)
	{
		$table_ary = array_merge(array($this->dice_table), $event['table_ary']);
		$event['table_ary'] = $table_ary;
	}

	public function save_forum_enable_dice($event)
	{
		$forum_data	= $event['forum_data'];
		$forum_data += array('forum_allow_dice' => $this->request->variable('forum_allow_dice', 0));
		$forum_data += array('forum_default_dice_mode' => $this->request->variable('forum_default_dice_mode', 1));
		$event->offsetSet('forum_data', $forum_data);
	}

	public function init_forum_enable_dice($event)
	{
		$forum_data	= $event['forum_data'];
		$forum_data += array('forum_allow_dice' => $this->request->variable('forum_allow_dice', 0));
		$forum_data += array('forum_default_dice_mode' => $this->request->variable('forum_default_dice_mode', 1));
		$event->offsetSet('forum_data', $forum_data);
	}

	public function forum_dice_template_data($event)
	{
		$tpl_data = $event['template_data'];
		$tpl_data['S_FORUM_ALLOW_DICE'] = $event['forum_data']['forum_allow_dice'];
		$tpl_data['S_FORUM_DEFAULT_DICE_MODE'] = $event['forum_data']['forum_default_dice_mode'];
		$event->offsetSet('template_data', $tpl_data);
	}

	public function fix_set_forum_id($event)
	{
		$this->forum_id = $event['forum_id'];
	}

	public function filter_bbcodes($event)
	{
		$sql = 'SELECT forum_allow_dice FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $this->forum_id;
		$result = $this->db->sql_query($sql);
		$forum_allow_dice = $this->db->sql_fetchfield('forum_allow_dice');
		$this->db->sql_freeresult($result);

		$sql_ary = $event['sql_ary'];

		if(($forum_allow_dice == 2) || ($forum_allow_dice == 1 && $this->auth->acl_get('f_allow_dice', $this->forum_id)))
		{
			$sql_ary['WHERE'] .= '';
		}
		else
		{
			$sql_ary['WHERE'] .= ' AND ' . $this->db->sql_in_set('b.bbcode_tag', array('dice', 'dice='), true);
		}

		$event['sql_ary'] = $sql_ary;
	}

	public function add_permission_cat($event)
	{
		$perm_cat = $event['categories'];
		$perm_cat['acp_dice'] = 'ACP_DICE';
		$event['categories'] = $perm_cat;

		$permission = $event['permissions'];
		$permission['a_dice']			= array('lang' => 'ACL_A_DICE',			'cat' => 'acp_dice');
		$permission['f_allow_dice']		= array('lang' => 'ACL_F_ALLOW_DICE',	'cat' => 'content');
		$permission['m_craft_dice']		= array('lang' => 'ACL_M_CRAFT_DICE',	'cat' => 'post_actions');
		$event['permissions'] = $permission;
	}

	private function _setremovability($diceID, $removability)
	{
		$sql = 'UPDATE ' . $this->dice_table . ' SET can_remove=' . $removability . ' WHERE id=' . $diceID;
		$this->db->sql_query($sql);
	}

	private function _checkDiceModes($modestring)
	{
		$mode_set = array('valid' => true, 'quote' => false, 'string' => false, 'total' => false);

		if($modestring != '')
		{
			$mode_array = explode(',', strtolower($modestring));

			foreach($mode_array as $current_part)
			{
				$current_part_b = trim($current_part);

				switch($current_part_b)
				{
					case 'quote':
						$mode_set['quote'] = true;
					break;
					case 'string':
						$mode_set['string'] = true;
					break;
					case 'total':
						$mode_set['total'] = true;
					break;
					default:
						$mode_set['valid'] = false;
					break;
				}
			}
		}

		return $mode_set;
	}

	private function _diceMathCheck($fct_string, $depth, $config_depth)
	{
		if (preg_match('/^(\((.+?)\)|([\-]?)(x))([\+\-\*\/])([\-]?[0-9]+(\.[0-9]+)?)$|^([\-]?[0-9]+(\.[0-9]+)?)([\+\-\*\/])(\((.+?)\)|([\-]?)(x))$/i', $fct_string, $hits))
		{
			if((isset($hits[4]) && $hits[4] == 'x') || (isset($hits[14]) && $hits[14] == 'x'))
			{
				return true;
			}
			else
			{
				if($config_depth > 0)
				{
					if($depth <= 1)
					{
						return false;
					}
					else
					{
						--$depth;
					}
				}

				$deeper = '';

				if(isset($hits[5]) && $hits[5] != '')
				{
					$deeper = $hits[2];
				}
				else
				{
					$deeper = $hits[12];
				}

				return $this->_diceMathCheck($deeper, $depth, $config_depth);
			}
		}
		else
		{
			return false;
		}
	}

	private function _diceMathCalc($fct_string, $total)
	{
		preg_match('/^(\((.+?)\)|([\-]?)(x))([\+\-\*\/])([\-]?[0-9]+(\.[0-9]+)?)$|^([\-]?[0-9]+(\.[0-9]+)?)([\+\-\*\/])(\((.+?)\)|([\-]?)(x))$/i', $fct_string, $hits);

		$operator = "";
		$a=0.0;
		$b=0.0;

		if(isset($hits[5]) && $hits[5] != "")
		{
			$operator = $hits[5];
			$b = floatval($hits[6]);

			if(isset($hits[2]) && $hits[2] != "")
			{
				$a = $this->_diceMathCalc($hits[2], $total);
			}
			else
			{
				$a = floatval($hits[3] . $total);
			}
		}
		else
		{
			$operator = $hits[10];
			$a = floatval($hits[8]);

			if(isset($hits[12]) && $hits[12] != "")
			{
				$b = $this->_diceMathCalc($hits[12], $total);
			}
			else
			{
				$b = floatval($hits[13] . $total);
			}
		}

		switch($operator)
		{
			case "+":
				return $a + $b;
			break;
			case "-":
				return $a - $b;
			break;
			case "*":
				return $a * $b;
			break;
			case "/":
				if($b == 0)
				{
					return 9000.1;
				}

				return $a / $b;
			break;
			default:
				return 0.0;
			break;
		}
	}

	private function _encodediceBBCode($dice_mode, $org_dice_roll_req)
	{
		$forum_id	= $this->forum_id;
		$post_id	= $this->post_id;
		$bbcode_uid	= $this->bbcode_uid;

		$this->dice_char = $this->language->lang('DICE_CHAR');

		//convert ascii code for round brackets to bracket character
		//@todo check relevance at phpbb updates
		$dice_roll_req = str_replace('&#40;', '(', $org_dice_roll_req);
		$dice_roll_req = str_replace('&#41;', ')', $dice_roll_req);

		//check if dice modes are valid
		$dice_info		= array();
		$math_string	= '';
		$newroll		= false;
		$new_math		= false;
		$numsides		= 0;
		$total			= 0.0;
		$roll_id		= 0;

		$mode_set = $this->_checkDiceModes($dice_mode);
		$valid_dicecode = $mode_set["valid"];

		// Check if dice roll is already encoded and the contained post id differes from the current post id = quoteing
		if (strpos($dice_roll_req, ':') !== false)
		{
			$roll_req_ary = explode(':', $dice_roll_req);

			$quote_post_id = (int) $roll_req_ary[0];
			$quote_roll_id = (int) $roll_req_ary[1];
			unset($roll_req_ary);

			if (is_numeric($quote_post_id) && is_numeric($quote_roll_id) && $quote_post_id <> $this->post_id)
			{
				return '[dice:' . $bbcode_uid . ']' . $org_dice_roll_req . '[/dice:' . $bbcode_uid . ']';
			}
		}

		//following solution is ugly as hell, but sadly preg_replace offers no means of extracting the replaced parts
		//this is used to check if there is a valid math function applied to the dice roll
		if($valid_dicecode && !preg_match('/^(nomath\((([0-9]+):)?([0-9]+)\)|(([0-9]+):)?([0-9]+))$/i' , $dice_roll_req))
		{
			$math_candidate = preg_replace('/(([1-9][0-9]*)' . $this->dice_char . '([1-9][0-9]*))|(' . $this->dice_char . '([1-9][0-9]*):(([1-9][0-9]*)(,([1-9][0-9]*))*))|f\(([0-9]+:)?[0-9]+\)/i', 'x', $dice_roll_req);

			//return $math_candidate;
			if(strip_tags($math_candidate) != 'x')
			{
				if($this->_diceMathCheck($math_candidate, $this->config['dice_math_depth'], $this->config['dice_math_depth']))
				{
					$math_string = $math_candidate;
					$new_math = true;
				}
				else
				{
					$valid_dicecode = false;
				}
			}
		}

		if($valid_dicecode)
		{
			if(preg_match('/([1-9][0-9]*)' . $this->dice_char . '([1-9][0-9]*)/i', $dice_roll_req, $dice_info))
			{
				$newroll = true;
				$numsides = (int) $dice_info[2];

				//second if() to prevent issues (perhaps not neccessary)
				$real_dice = explode(":", $this->config['dice_real_dice']);

				if(($this->config['dice_real_dice_only'] && !empty($real_dice) && !in_array($numsides, $real_dice)) || ($numsides > 255) || (($dice_info[1] >= $this->config['dice_max_dice']) && ($this->config['dice_max_dice'] != 0)) || ($dice_info[1] < 1))
				{
					$valid_dicecode = false;
				}
				else
				{
		  			//this is a valid dice roll, add it to the database and encode it (1 => numdie(not directly stored in database anymore) , 2 => diesides)
		  			//generate the rolls
		  			$dice_roll = '';
		  			$numdice = 0;

		  			for($i = 0; $i < (int) $dice_info[1]; ++$i)
		  			{
		  				$singleroll = mt_rand(1,$numsides);
		  				$dice_roll .= $singleroll . ':';
		  				$total += $singleroll;
		  				++$numdice;
		  			}
		    	}
			}
			else if($this->auth->acl_get('m_craft_dice', $forum_id) && preg_match('/' . $this->dice_char . '([1-9][0-9]*):(([1-9][0-9]*)(,([1-9][0-9]*))*)/i' , $dice_roll_req, $dice_info))
			{
				$newroll = true;
				$dice_array = explode(",", $dice_info[2]);
				$numdice = sizeof($dice_array);
				$numsides = (int) $dice_info[1];

				if(($this->config['dice_real_dice_only'] && !empty($real_dice) && !in_array($numsides, $real_dice)) || ($numsides > 255) || (($numdice >= $this->config['dice_max_dice']) && ($this->config['dice_max_dice'] != 0)) || ($numsides < 1))
				{
		  			$valid_dicecode = false;
		  		}
		  		else
				{
				    $dice_roll = '';

		  			for($i = 0; $i < $numdice; ++$i)
		  			{
		  				if($dice_array[$i] <= $numsides)
						{
		      				$dice_roll .= $dice_array[$i] . ':';
		      				$total += $dice_array[$i];
		      			}
		      			else
						{
							$valid_dicecode = false;
						}
		  			}
				}
			}
			else if(!$new_math && preg_match('/^(([0-9]+):)?([0-9]+)$/i', $dice_roll_req, $dice_info))
			{
				if(($dice_info[2] != "" && $dice_info[2] == $post_id) || $dice_info[2] == '')
				{
					$roll_id = $dice_info[3];

					$old_roll = $this->_get_old_roll($post_id, $roll_id);

					if(!$old_roll)
					{
						$valid_dicecode = false;
					}
				}
				else
				{
					$roll_id = $dice_roll_req;
				}
			}
			else if(!$new_math && preg_match('/^nomath\((([0-9]+):)?([0-9]+)\)$/i', $dice_roll_req, $dice_info))
			{
				if($dice_info[2] != "" && $dice_info[2] != $post_id)
				{
					$valid_dicecode = false;
				}
				else
				{
					$roll_id = $dice_info[3];

					$old_roll = $this->_get_old_roll($post_id, $roll_id);

					if(!$old_roll)
					{
						$valid_dicecode = false;
					}
					else
					{
						$dice_array = explode(':', $old_roll['dice_roll']);

						for($i = 0; $i < $old_roll['numdice']; ++$i)
						{
							$total += $dice_array[$i];
						}

						$sql = 'UPDATE ' . $this->dice_table . ' SET total=' . $total . ', math_string="" WHERE post_id=' . $post_id . ' AND roll_id=' . $roll_id;
						$this->db->sql_query($sql);
					}
				}
			}
			else if($new_math && preg_match('/f\((([0-9]+):)?([0-9]+)\)/i', $dice_roll_req, $dice_info))
			{
				if(($dice_info[2] != "" && $dice_info[2] != $post_id) || $mode_set["quote"])
				{
					$valid_dicecode = false;
				}
				else
				{
					$roll_id = $dice_info[3];

					$old_roll = $this->_get_old_roll($post_id, $roll_id);

					if(!$old_roll)
					{
						$valid_dicecode = false;
					}
					else
					{
						$dice_array = explode(':', $old_roll['dice_roll']);

		    			for($i = 0; $i < $old_roll['numdice']; ++$i)
						{
							$total += $dice_array[$i];
						}

						$total = $this->_diceMathCalc($math_string, $total);

						$sql = 'UPDATE ' . $this->dice_table . ' SET total=' . $total . ', math_string="' . $math_string . '" WHERE post_id=' . $post_id . ' AND roll_id=' . $roll_id;
						$this->db->sql_query($sql);
					}
				}
			}
			else
			{
				$valid_dicecode = false;
			}
		}

		if($valid_dicecode)
		{
			$modes = "";
			$modes .= ($mode_set["quote"] ? "quote," : "") . ($mode_set["string"] ? "string," : "") . ($mode_set["total"] ? "total" : "");

			if($modes != ""){
				$modes = rtrim($modes, ",");
				$modes = '=' . $modes;
			}

			if($newroll)
			{
				$dice_roll = rtrim($dice_roll, ":");

				//generate the roll_id
				$roll_id = -1;

				do
				{
					++$roll_id;

					//ensure that this code is not already taken
					$sql = 'SELECT * FROM ' . $this->dice_table . ' WHERE post_id=' . $post_id . ' AND roll_id=' . $roll_id . ' AND user_id=' . $this->user->data['user_id'];
					$result = $this->db->sql_query($sql);
				}
				while($this->db->sql_fetchrow($result));

				$this->db->sql_freeresult($result);

				//check if number of allowed rolls per post isn't exceeded;
				if(($roll_id > ($this->config['dice_max_rolls'] ? $this->config['dice_max_rolls'] -1 : 255)) || ($roll_id > 255))
				{
					$valid_dicecode = false;
				}
				else
				{
					if($new_math)
					{
						$total = $this->_diceMathCalc($math_string, $total);
					}

					//insert into the database
					$sql = 'INSERT INTO ' . $this->dice_table . ' ' . $this->db->sql_build_array('INSERT', array(
						'post_id'		=> $post_id,
						'dice_roll'		=> $dice_roll,
						'numsides'		=> $numsides,
						'numdice'		=> $numdice,
						'math_string'	=> $math_string,
						'total'			=> $total,
						'can_remove'	=> 0,
						'roll_id'		=> $roll_id,
						'user_id'		=> $this->user->data['user_id']
					));

					$this->db->sql_query($sql);
				}
			}

			//return the new code so that it may be processed by the BBCode parser
			return '[dice' . $modes . ':' . $bbcode_uid . ']' . $post_id . ':' . $roll_id . '[/dice:' . $bbcode_uid . ']';
		}

		if ($dice_mode)
		{
			$add_mode = '=' . $dice_mode;
		}
		return '[dice' . $add_mode . ':' . $bbcode_uid . ']' . $org_dice_roll_req . '[/dice:' . $bbcode_uid . ']';
	}

	protected function _get_old_roll($post_id, $roll_id)
	{
		$sql = 'SELECT * FROM ' . $this->dice_table . ' WHERE post_id=' . (int) $post_id . ' AND roll_id=' . (int) $roll_id;
		$result = $this->db->sql_query($sql);
		$old_roll = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return ($old_roll) ? $old_roll : false;
	}

	public function prepare_post_text_before_format($event)
	{
		$message_parser				= $event['message_parser'];
		$message_parser->message	= preg_replace('#\[dice=quote\](.*?)\[\/dice\]#is', '[dicequote]$1[/dice]', $message_parser->message);
		$event['message_parser']	= $message_parser;
	}

	public function prepare_post_text_for_save($event)
	{
		$data					= $event['data'];
		$uid					= $data['bbcode_uid'];
		$data['message']		= preg_replace('#\[dicequote\](.*?)\[\/dice\]#is', '[dice=quote:' . $uid . ']$1[/dice:' . $uid . ']', $data['message']);
		$data['message_md5']	= md5($data['message']);
		$event['data']			= $data;
	}

	public function check_and_role_dices($event)
	{
		$data		= $event['data'];
		$dmessage	= $data['message'];

		$this->forum_id		= $event['forum_id'];
		$this->post_id		= $data['post_id'];
		$this->bbcode_uid	= $data['bbcode_uid'];

		$sql = 'SELECT forum_allow_dice FROM '. FORUMS_TABLE . ' WHERE forum_id=' . $this->forum_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if($row['forum_allow_dice'])
		{
			if(($row['forum_allow_dice'] == 2) || $this->auth->acl_get('f_allow_dice', $this->forum_id))
			{
				//add new dice codes
				$dmessage = preg_replace_callback('#\[dice(=(.*?)?)?\](.+?)\[/dice?\]#is', function($match) { return $this->_encodediceBBCode($match[2], $match[3]); }, $dmessage);
			}

			//Make the dice code permanent,
			//check on the existing dice codes
			//grab all the dice codes for this post
			$sql = 'SELECT * FROM ' . $this->dice_table . ' WHERE post_id=' . $this->post_id;
			$result = $this->db->sql_query($sql);

			while($row = $this->db->sql_fetchrow($result))
			{
				$carrydmatch = false;
				$offset = 0;
				$carryarr = NULL;

				//is the dice code not present in the message?
				do
				{
					$carrydmatch = preg_match('#\[dice(=([^\]]*?)?)?' . ($data['enable_bbcode']? ':' . $this->bbcode_uid : '') . '\](' . $this->post_id . ':' . $row['roll_id'] . ')\[/dice' . ($data['enable_bbcode']? ':' . $this->bbcode_uid : '') . '\]#is', $dmessage, $carryarr, PREG_OFFSET_CAPTURE, $offset);

					if($carrydmatch)
					{
						$dmode_set = $this->_checkDiceModes($carryarr[2][0]);
						$offset = $carryarr[0][1] + strlen($carryarr[0][0]);
					}
				}
				while($carrydmatch && (!$dmode_set["valid"] || $dmode_set["quote"])); //loop as long as dice code is found, but with invalid tags/quote tag; dice code quoted with [dice]post_id:roll_id[/dice]] are already unaffected due to the pattern

				if(!$carrydmatch)
				{
					//if the user does not have the permissions to remove it, add the dice code at the end of the post
					if(!$row['can_remove'] && !$this->auth->acl_get('m_', $this->forum_id))
					{
						$dmessage = $dmessage . "\n" . '[dice]' . $this->post_id . ':' . $row['roll_id'] . '[/dice]';
					}
					else
					{
						//otherwise, the dice has been removed and remove it from the database for cleanup
						$sql = 'DELETE FROM ' . $this->dice_table . ' WHERE id=' . $row['id'];
						$this->db->sql_query($sql);
					}
				}
				else
				{
					//remove roll doubles
					$carrydmatch = false;
					$carryarr = NULL;

					do
					{
						$carrydmatch = preg_match('#\[dice(=([^\]]*?)?)?' . ($data['enable_bbcode']? ':' . $this->bbcode_uid : '') . '\](' . $this->post_id . ':' . $row['roll_id'] . ')\[/dice' . ($data['enable_bbcode']? ':' . $this->bbcode_uid : '') . '\]#is', $dmessage, $carryarr, PREG_OFFSET_CAPTURE, $offset);

						if($carrydmatch)
						{
							$dmode_set = $this->_checkDiceModes($carryarr[2][0]);

							if($dmode_set["valid"] && !$dmode_set["quote"])
							{
								$dmessage = substr($dmessage, 0, $carryarr[0][1]) . substr($dmessage, $carryarr[0][1] + strlen($carryarr[0][0]));
							}
							else
							{
								$offset = $carryarr[0][1] + strlen($carryarr[0][0]);
							}
						}
					}
					while($carrydmatch); //loop as long as dice code is found, replace if it is valid and not quote.
				}
			}

			$this->db->sql_freeresult($result);

			$sql = 'UPDATE ' . POSTS_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE', array(
				'post_text'			=> $dmessage,
				'post_checksum'		=> md5($dmessage),
			)) . ' WHERE post_id = ' . $this->post_id;
			$this->db->sql_query($sql);
		}
	}

	public function format_dice_rolls($event)
	{
		$post_list		= $event['post_list'];
		$rowset			= $event['rowset'];

		$this->forum_id	= $event['topic_data']['forum_id'];

		for ($i = 0, $end = count($post_list); $i < $end; ++$i)
		{
			if (!isset($rowset[$post_list[$i]]))
			{
				continue;
			}

			$row = $rowset[$post_list[$i]];
			$this->post_id	= $row['post_id'];

			$message	= $row['post_text'];
			$bbcode_uid	= $row['bbcode_uid'];
			$bitfield	= $row['bbcode_bitfield'];

			decode_message($message, $bbode_uid);
			$message = str_replace(':' . $bbcode_uid . ']', ']', $message);

			$message = preg_replace_callback("#\[dice\](.+?)\[/dice\]#is", function($match) { return $this->_decodedice($match[1], ''); }, $message);
			$message = preg_replace_callback("#\[dice=(.+?)\](.*?)(([0-9]+:)?[0-9]+)(.*?)\[/dice\]#is", function($match) { return $this->_decodedice($match[3], $match[1]); }, $message);

			$allow_smilies	= ($this->config['allow_smilies'] && $this->user->optionget('smilies')) ? true : false;
			$allow_bbcode	= ($this->config['allow_bbcode'] && $this->user->optionget('bbcode')) ? true : false;
			$allow_urls	= true;
			$flags = (($allow_bbcode) ? OPTION_FLAG_BBCODE : 0) + (($allow_smilies) ? OPTION_FLAG_SMILIES : 0) + (($allow_urls) ? OPTION_FLAG_LINKS : 0);

			generate_text_for_storage($message, $bbcode_uid, $bitfield, $flags, $allow_bbcode, $allow_urls, $allow_smilies);
						
			$row['post_text'] = $message;

			$rowset[$post_list[$i]] = $row;
		}

		$event['rowset'] = $rowset;
	}

	public function display_rolls($event)
	{
		$post_row	= $event['post_row'];
		$message	= $post_row['MESSAGE'];

		$message	= preg_replace_callback('#__DICE__([0-9]+)__([0-9]+)__DICE__#s', function($match) { return $this->_displaydice($match[1], $match[2]); }, $message);

		$post_row['MESSAGE']	= $message;
		$event['post_row']		= $post_row;
	}

	public function decode_post_text($event)
	{
		$message_text	= $event['message_text'];
		$bbcode_uid		= $event['bbcode_uid'];
		if ($bbcode_uid)
		{
			$message_text	= str_replace(':' . $bbcode_uid . ']', ']', $message_text);
		}
		$event['message_text'] = $message_text;
	}

	private function _decodedice($roll_code, $modes)
	{
		$this->dice_char = $this->language->lang('DICE_CHAR');

		if($this->post_id)
		{
			//identify dice roll
			if(substr_count($roll_code, ":") > 0)
			{
				$pos = strrpos($roll_code, ":");
				$post_id = (int) substr($roll_code, 0, $pos);
				$roll_id = (int) substr($roll_code, $pos +1);
			}
			else
			{
				$post_id = $this->post_id;
				$roll_id = (int) $roll_code;

				if(($roll_id == 0) AND ($roll_code != '0'))
				{
					return $this->language->lang('INVALID_DICE_CODE');
				}
			}

			//get the dice roll from the data array (previous cached from the database for the current topic)
			$dice_roll = (isset($this->dice_roll_data[$post_id][$roll_id])) ? $this->dice_roll_data[$post_id][$roll_id] : false;

			if(!$dice_roll)
			{
				return $this->language->lang('INVALID_DICE_CODE');
			}

			$mode_array = $this->_checkDiceModes($modes);

			if(!$mode_array["valid"])
			{
				return $this->language->lang('INVALID_DICE_MODES');
			}
			else
			{
				$dmode_string = $mode_array["string"];
				$dmode_total = $mode_array["total"];
				$dmode_quote = $mode_array["quote"];
			}

			if(!($dmode_string || $dmode_total))
			{
				$sql = 'SELECT forum_default_dice_mode FROM ' . FORUMS_TABLE . ' WHERE forum_id =' . $this->forum_id;
				$result = $this->db->sql_query($sql);
				$default_dmode = $this->db->sql_fetchfield('forum_default_dice_mode');
				$dmode_string = (($default_dmode & 1) == 1);
				$dmode_total = (($default_dmode & 2) == 2);
			}

			if(!($dmode_string || $dmode_total))
			{
				$dmode_string = true;
			}

			$dice_abbr = $dice_roll['numdice'] . $this->dice_char . $dice_roll['numsides'];

			if($dice_roll['math_string'] != "")
			{
				$dmode_total = true;
				$dice_abbr = preg_replace('/x/', $dice_abbr, $dice_roll['math_string']);
			}

			//get display mode
			$carry_disp_opts = explode(":", $this->config['dice_themes']);
			$carry_disp = $this->user->data['user_dice_disp'];
			$dice_disp = (sizeof($carry_disp_opts) > $carry_disp) ? $carry_disp_opts[$carry_disp] : $carry_disp_opts[0];

			//get the dice roll, create dice roll text for the roll itself
			$offset = -1;
			$roll = '';

			if($dmode_string)
			{
				if($dice_disp == 'text')
				{
					$roll = preg_replace('#([0-9]+)[:]?#', "<span title=\"\$1{$this->language->lang('ON_A_DIE')}{$dice_roll['numsides']}\">\$1</span>, ", $dice_roll['dice_roll']);
					$roll = rtrim($roll, ", ");
				}
				else
				{
					$roll = preg_replace('#([0-9]+)[:]?#', "<img src=\"{$this->ext_path_web}includes/skins/{$dice_disp}/d{$dice_roll['numsides']}_\$1.gif\" alt=\"\$1,\" title=\"\$1{$this->language->lang('ON_A_DIE')}{$dice_roll['numsides']}\" /> ", $dice_roll['dice_roll']);
					$roll = rtrim($roll);
					$pos = strrpos($roll, '," title="');
					$roll = substr($roll, 0, $pos) . substr($roll, $pos+1);
				}
				$roll = '<b>' . $roll . '</b>';
			}
			else
			{
				$roll = preg_replace('#([0-9]+)[:]?#', "\$1, ", $dice_roll['dice_roll']);
				$roll = rtrim($roll, ", ");
			}

			$haystack = ($this->language->lang('PATTERN_ROLL_STRING')) . ($dmode_total ? $this->language->lang('PATTERN_ROLL_TOTAL') : '') . (!$dmode_string ? '.' : (':<br>' . $roll));

			if(!$dmode_string)
			{
				$haystack = '<span title="' . $roll . '">' . $haystack . '</span>';
			}

			//create entire replacement text
			if($dmode_quote || $dice_roll['post_id'] != $this->post_id)
			{
				$urllink = 'viewtopic.php?p=' . $dice_roll['post_id'] . '#p' . $dice_roll['post_id'];
				$needles = array('&gt;','&lt;','[%u]','[%d]','[%t]','[%l]');
				$replace = array('>', '<', $dice_roll['username'], $dice_abbr, floatval($dice_roll['total']), $urllink);
				//parse the dice roll with the dice values
				$dice_roll['disproll'] = str_replace($needles , $replace , '<a href="[%l]">'. $this->language->lang('ROLLS_ORIGINAL_POST') . ':</a> ' . $haystack);
			}
			else
			{
				$needles = array('[%u]','[%d]','[%t]');
				$replace = array($dice_roll['username'], $dice_abbr, floatval($dice_roll['total']));
				$dice_roll['disproll'] = str_replace($needles , $replace , $haystack);

				//if this is the original post (checked above) and the user is a moderator, add a removability link
				if($this->auth->acl_get('m_edit', $this->forum_id))
				{
					$urllink = 'viewtopic.php?p=' . $dice_roll['post_id'];
					if($dice_roll['can_remove'])
					{
						$dice_roll['disproll'] .= '<br /><a href="' . $urllink . '&diceID=' . $dice_roll['id'] . '&removability=0">' . $this->language->lang('MAKE_UNREMOVABLE') . '</a>';
					}
					else{
						$dice_roll['disproll'] .= '<br /><a href="' . $urllink . '&diceID=' . $dice_roll['id'] . '&removability=1">' . $this->language->lang('MAKE_REMOVABLE') . '</a>';
					}
				}
			}

			$this->dice_roll[$this->post_id][$roll_id] = '<div>' . $dice_roll['disproll'] . '</div>';
		}
		else
		{
			$this->dice_roll[$this->post_id][$roll_id] = $this->language->lang('INVALID_DICE_CODE');
		}

		return '__DICE__' . $this->post_id . '__' . $roll_id . '__DICE__';
	}

	private function _displaydice($post_id, $roll_id)
	{
		if (isset($this->dice_roll[$post_id][$roll_id]))
		{
			return $this->dice_roll[$post_id][$roll_id];
		}
		else
		{
			return $this->language->lang('INVALID_DICE_CODE');
		}
	}
}
