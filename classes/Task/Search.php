<?php defined('SYSPATH') or die('No direct script access.');

/**
 * This task interacts with search index database.
 *
 * It can accept the following options:
 *  - scan: crawl all pages
 *  - optimize: optimize the index database
 */
class Task_Search extends Minion_Task
{
	protected $_defaults = array(
		'mode' => FALSE
	);

	protected $_options = array(
		'mode'
	);

	protected $index;

	public function initialize()
	{
		// autoload Zend
		if ($path = Kohana::find_file('vendor', 'Zend/Loader'))
		{
			ini_set('include_path', ini_get('include_path').PATH_SEPARATOR.dirname(dirname($path)));
		}

		require_once Kohana::find_file('vendor/Zend/Loader', 'Autoloader');

		Zend_Loader_Autoloader::getInstance();

		// set correct analaysis analizer
		Zend_Search_Lucene_Analysis_Analyzer::setDefault(new Zend_Search_Lucene_Analysis_Analyzer_Common_Utf8Num_CaseInsensitive());

		$cache = APPPATH.'cache/lucene';

		try
		{
			$this->index = Zend_Search_Lucene::open($cache);
		}
		catch (Zend_Search_Lucene_Exception $e)
		{
			$this->index = Zend_Search_Lucene::create($cache);
		}
	}

	public function search()
	{
		$index = Zend_Search_Lucene::open(APPPATH.'cache/lucene');

		$query = Zend_Search_Lucene_Search_QueryParser::parse('foo');
		$hits = $index->find($query);

		$found = [];

		foreach ($hits as $hit)
		{
			if (in_array($hit->page, $found))
				continue;

			$found[] = $hit->page;

			$page = ORM::factory('Prime_Page', $hit->page);

			echo ' - '.$page->name.' ('.$hit->score.')<br>';
			echo $page->uri().'<br>';

		}
	}

	/**
	 * Execute task
	 *
	 * @return null
	 */
	protected function _execute(array $params)
	{
		$this->initialize();

		$mode = Arr::get($params, 'mode', NULL);

		if ($mode === 'stats')
		{
			Minion_CLI::write('Total '.$this->index->numDocs().' documents in index');
		}

		if ($mode === 'scan')
		{
			Minion_CLI::write('Crawling website pages for index...');

			$begin = $this->index->numDocs();

			// loop through websites
			$pages = ORM::factory('Prime_Page')
			->find_all();

			foreach ($pages as $i => $page)
			{
				$percent = Minion_CLI::color(floor(($i / count($pages)) * 100).'%', 'red');

				Minion_CLI::write_replace('['.$percent.'] Scanning page ' . $page->name .'...', ($i === (count($pages) - 1)));

				$hits = $this->index->find('page:'.$page->id);

				foreach ($hits as $hit)
				{
					$this->index->delete($hit->id);
				}

				$response = Request::factory('/')
				->query(['pageid' => $page->id])
				->execute();

				$doc = Zend_Search_Lucene_Document_Html::loadHTML($response->body());
				$doc->addField(Zend_Search_Lucene_Field::Keyword('page', $page->id));

				// add document to index
				$this->index->addDocument($doc);
			}

			Minion_CLI::write('Done indexing '.($this->index->numDocs()-$begin));

			Minion_CLI::write('Total '.$this->index->numDocs().' documents of maximum '.$this->index->maxDoc().' in index');

		}
		
		if ($mode === 'optimize')
		{
			Minion_CLI::write('Optimizing Lucene index...');

			$this->index->optimize();

			Minion_CLI::write('Done');
		}
	}
}