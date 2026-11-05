from anime_parsers_ru import KodikParser, KodikList, KodikSearch
import sys

from pprint import pprint
initial = KodikSearch().shikimori_id(sys.argv[1:]).execute()
# Получаем список переводов и серий
data = initial.results[0].raw_data
pprint(data)
#exit

#query = KodikList().title('Жестокий Джек 2').limit(5)
#data = query.execute()
#pprint(data)
#data = query.execute()
#print(type(data))
#print(data.results)
#print(data.results[0].link)
#print(data.raw_data)
